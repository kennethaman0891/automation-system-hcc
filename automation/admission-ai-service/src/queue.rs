use std::sync::atomic::{AtomicU64, AtomicUsize, Ordering};
use std::sync::Arc;
use tokio::sync::{mpsc, oneshot};
use tracing::{info, warn};

use crate::intent::IntentClassifier;
use crate::knowledge::KnowledgeBase;
use crate::ollama::OllamaClient;
use crate::types::{AdmissionDraftResponse, EnquiryRequest};
use crate::validator::SafetyValidator;

pub struct InferenceJob {
    pub request: EnquiryRequest,
    pub responder: oneshot::Sender<Result<AdmissionDraftResponse, String>>,
}

struct InflightGuard {
    inflight: Arc<AtomicUsize>,
}

impl Drop for InflightGuard {
    fn drop(&mut self) {
        self.inflight.fetch_sub(1, Ordering::SeqCst);
    }
}

#[derive(Clone)]
pub struct InferenceQueue {
    sender: mpsc::Sender<InferenceJob>,
    queued_count: Arc<AtomicUsize>,
    inflight: Arc<AtomicUsize>,
    total_processed: Arc<AtomicU64>,
}

impl InferenceQueue {
    pub fn new(
        max_capacity: usize,
        worker_count: usize,
        knowledge_base: Arc<KnowledgeBase>,
        ollama_client: Arc<OllamaClient>,
        default_model: String,
    ) -> (Self, Vec<tokio::task::JoinHandle<()>>) {
        let workers = worker_count.clamp(1, 4);
        let (sender, receiver) = mpsc::channel::<InferenceJob>(max_capacity);
        let receiver = Arc::new(tokio::sync::Mutex::new(receiver));
        let queued_count = Arc::new(AtomicUsize::new(0));
        let inflight = Arc::new(AtomicUsize::new(0));
        let total_processed = Arc::new(AtomicU64::new(0));

        if workers > 1 {
            warn!(
                "AI_INFERENCE_CONCURRENCY={} loads more than one inference at a time. Keep this at 1 on an 8 GB host.",
                workers
            );
        }

        let mut handles = Vec::with_capacity(workers);
        for worker_id in 0..workers {
            let receiver = receiver.clone();
            let knowledge_base = knowledge_base.clone();
            let ollama_client = ollama_client.clone();
            let default_model = default_model.clone();
            let q_count_worker = queued_count.clone();
            let inflight_worker = inflight.clone();
            let total_proc_worker = total_processed.clone();

            handles.push(tokio::spawn(async move {
                info!(
                    "Inference worker {} started. Configured concurrency is {}.",
                    worker_id, workers
                );

                loop {
                    let job = {
                        let mut guard = receiver.lock().await;
                        guard.recv().await
                    };
                    let Some(job) = job else {
                        break;
                    };

                    q_count_worker.fetch_sub(1, Ordering::SeqCst);
                    inflight_worker.fetch_add(1, Ordering::SeqCst);
                    let _guard = InflightGuard {
                        inflight: inflight_worker.clone(),
                    };

                    let req = job.request;
                    let target_model = req.model.clone().unwrap_or_else(|| default_model.clone());

                    info!(
                        "Processing enquiry for chat_id={}, message=\"{}\" with model={}",
                        req.chat_id, req.current_message, target_model
                    );

                    let (heuristic_intent, _confidence, parent, student) =
                        IntentClassifier::classify_and_extract(
                            &req.current_message,
                            &req.conversation_context,
                            req.parent_name.as_deref(),
                            req.parent_phone.as_deref(),
                        );

                    let slice = knowledge_base.retrieve_slice(
                        &heuristic_intent,
                        student.grade_applying_for.as_deref(),
                        &req.current_message,
                    );

                    let mut draft_res = ollama_client
                        .generate_draft(
                            &target_model,
                            &req.current_message,
                            &req.conversation_context,
                            &slice,
                            &heuristic_intent,
                            &parent,
                            &student,
                        )
                        .await;

                    match draft_res.as_mut() {
                        Ok(draft) => {
                            SafetyValidator::validate_and_harden(
                                draft,
                                &slice,
                                &req.current_message,
                            );
                        }
                        Err(e) => {
                            warn!(
                                "Draft generation failed: {}. Providing verified deterministic fallback.",
                                e
                            );
                            let mut fallback = OllamaClient::deterministic_fallback(
                                &heuristic_intent,
                                &parent,
                                &student,
                                &slice,
                                &req.current_message,
                                e,
                            );
                            SafetyValidator::validate_and_harden(
                                &mut fallback,
                                &slice,
                                &req.current_message,
                            );
                            draft_res = Ok(fallback);
                        }
                    }

                    total_proc_worker.fetch_add(1, Ordering::SeqCst);
                    drop(_guard);

                    let _ = job.responder.send(draft_res);
                }

                info!("Inference worker {} channel closed.", worker_id);
            }));
        }

        let queue = Self {
            sender,
            queued_count,
            inflight,
            total_processed,
        };

        (queue, handles)
    }

    /// Submit a job to the FIFO queue and await result
    pub async fn submit(&self, request: EnquiryRequest) -> Result<AdmissionDraftResponse, String> {
        let (responder, receiver) = oneshot::channel();
        let job = InferenceJob { request, responder };

        self.queued_count.fetch_add(1, Ordering::SeqCst);

        if let Err(e) = self.sender.send(job).await {
            self.queued_count.fetch_sub(1, Ordering::SeqCst);
            return Err(format!("Failed to enqueue enquiry request: {}", e));
        }

        receiver
            .await
            .map_err(|_| "Worker dropped responder channel".to_string())?
    }

    pub fn get_status(&self) -> (usize, bool, u64) {
        (
            self.queued_count.load(Ordering::SeqCst),
            self.inflight.load(Ordering::SeqCst) > 0,
            self.total_processed.load(Ordering::SeqCst),
        )
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[tokio::test]
    async fn closed_queue_does_not_leak_queued_count() {
        let kb = Arc::new(KnowledgeBase::default());
        let client = Arc::new(OllamaClient::new("http://127.0.0.1:9".to_string(), 5));
        let (queue, handles) = InferenceQueue::new(4, 1, kb, client, "llama3.2:1b".to_string());
        for h in handles {
            h.abort();
            let _ = h.await;
        }
        let req = EnquiryRequest {
            chat_id: 1,
            message_id: None,
            parent_phone: None,
            parent_name: None,
            current_message: "Fees?".to_string(),
            conversation_context: vec![],
            model: None,
        };
        let res = queue.submit(req).await;
        assert!(res.is_err());
        let (queued, is_proc, _) = queue.get_status();
        assert_eq!(queued, 0);
        assert!(!is_proc);
    }

    #[tokio::test]
    async fn multi_worker_completes_without_stuck_processing_flag() {
        let kb = Arc::new(KnowledgeBase::default());
        let client = Arc::new(OllamaClient::new("http://127.0.0.1:9".to_string(), 5));
        let (queue, handles) = InferenceQueue::new(8, 2, kb, client, "llama3.2:1b".to_string());

        let mut futs = Vec::new();
        for idx in 0..3u64 {
            let q = queue.clone();
            futs.push(tokio::spawn(async move {
                q.submit(EnquiryRequest {
                    chat_id: idx,
                    message_id: Some(idx),
                    parent_phone: None,
                    parent_name: None,
                    current_message: "School timings?".to_string(),
                    conversation_context: vec![],
                    model: None,
                })
                .await
            }));
        }

        for f in futs {
            let res = f.await.unwrap();
            assert!(res.is_ok());
        }

        let (queued, is_proc, processed) = queue.get_status();
        assert_eq!(queued, 0);
        assert!(!is_proc);
        assert_eq!(processed, 3);

        for h in handles {
            h.abort();
            let _ = h.await;
        }
    }
}
