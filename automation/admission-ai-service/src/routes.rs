use std::sync::Arc;
use axum::{
    extract::{Request, State},
    http::StatusCode,
    middleware::{self, Next},
    response::{IntoResponse, Response},
    routing::{get, post},
    Json, Router,
};
use serde_json::json;
use tower_http::trace::TraceLayer;
use tracing::{error, info};

use crate::benchmark::BenchmarkSuite;
use crate::config::AppConfig;
use crate::intent::IntentClassifier;
use crate::knowledge::KnowledgeBase;
use crate::ollama::OllamaClient;
use crate::queue::InferenceQueue;
use crate::types::{
    AdmissionDraftResponse, AdmissionIntent, EnquiryRequest, ResultCopilotRequest,
    TriageInboundRequest,
};
use crate::validator::SafetyValidator;

#[derive(Clone)]
pub struct AppState {
    pub config: AppConfig,
    pub knowledge_base: Arc<KnowledgeBase>,
    pub ollama_client: Arc<OllamaClient>,
    pub queue: InferenceQueue,
}

fn constant_time_eq(a: &[u8], b: &[u8]) -> bool {
    if a.len() != b.len() {
        return false;
    }
    let mut diff: u8 = 0;
    for (x, y) in a.iter().zip(b.iter()) {
        diff |= x ^ y;
    }
    diff == 0
}

async fn require_ai_secret(
    State(state): State<AppState>,
    req: Request,
    next: Next,
) -> Response {
    if let Some(expected) = state.config.ai_service_secret.as_deref() {
        let provided = req
            .headers()
            .get("x-ai-service-secret")
            .and_then(|v| v.to_str().ok())
            .unwrap_or("");
        if !constant_time_eq(provided.as_bytes(), expected.as_bytes()) {
            return (
                StatusCode::UNAUTHORIZED,
                Json(json!({ "error": "Unauthorized: missing or invalid X-AI-Service-Secret" })),
            )
                .into_response();
        }
    }
    next.run(req).await
}

pub fn create_router(state: AppState) -> Router {
    Router::new()
        .route("/api/process", post(process_enquiry))
        .route("/api/health", get(health_check))
        .route("/api/queue-status", get(queue_status))
        .route("/api/benchmark", post(run_benchmark_route))
        .route("/api/knowledge", get(knowledge_topics))
        .route("/api/validate", post(validate_draft_route))
        .route("/api/triage-inbound", post(triage_inbound_route))
        .route("/api/result-copilot", post(result_copilot_route))
        .layer(middleware::from_fn_with_state(state.clone(), require_ai_secret))
        .layer(TraceLayer::new_for_http())
        .with_state(state)
}

/// POST /api/process - Enqueue and process parent enquiry
async fn process_enquiry(
    State(state): State<AppState>,
    Json(payload): Json<EnquiryRequest>,
) -> impl IntoResponse {
    info!("Received enquiry request for chat_id={}", payload.chat_id);

    match state.queue.submit(payload).await {
        Ok(draft) => (StatusCode::OK, Json(draft)).into_response(),
        Err(e) => {
            error!("Failed to process enquiry: {}", e);
            (
                StatusCode::INTERNAL_SERVER_ERROR,
                Json(json!({ "error": e })),
            )
                .into_response()
        }
    }
}

/// GET /api/health - Health check
async fn health_check(State(state): State<AppState>) -> impl IntoResponse {
    let ollama_models = state.ollama_client.check_health().await.unwrap_or_default();
    let (queued, busy, total) = state.queue.get_status();

    Json(json!({
        "status": "healthy",
        "service": "HCC Local AI Admission Assistant",
        "version": "1.0.0",
        "platform_target": "Windows Intel CPU, 8 GB RAM, 500 GB Storage",
        "single_worker_queue": {
            "queued_requests": queued,
            "is_busy": busy,
            "total_processed": total,
            "concurrency_limit": state.config.inference_concurrency
        },
        "default_model": state.config.default_model,
        "ollama_available_models": ollama_models,
        "knowledge_topics_loaded": state.knowledge_base.documents.keys().collect::<Vec<_>>()
    }))
}

/// GET /api/queue-status - Current queue depth and worker status
async fn queue_status(State(state): State<AppState>) -> impl IntoResponse {
    let (queued, busy, total) = state.queue.get_status();
    Json(json!({
        "queued": queued,
        "is_processing": busy,
        "total_processed": total,
        "max_capacity": state.config.max_queue_depth
    }))
}

/// POST /api/benchmark - Run benchmark suite on candidate models
async fn run_benchmark_route(
    State(state): State<AppState>,
    Json(body): Json<serde_json::Value>,
) -> impl IntoResponse {
    let default_models = vec!["llama3.2:1b".to_string(), "llama3.2:latest".to_string()];
    let candidate_models = body
        .get("models")
        .and_then(|m| m.as_array())
        .map(|arr| {
            arr.iter()
                .filter_map(|v| v.as_str().map(|s| s.to_string()))
                .collect::<Vec<_>>()
        })
        .unwrap_or(default_models);

    info!("Starting benchmark for candidate models: {:?}", candidate_models);
    let results = BenchmarkSuite::run_benchmark(
        state.ollama_client.clone(),
        state.knowledge_base.clone(),
        &candidate_models,
    )
    .await;

    Json(json!({
        "status": "success",
        "hardware_target": "Windows Intel CPU, 8 GB RAM, 500 GB Storage",
        "benchmark_results": results
    }))
}

/// GET /api/knowledge - List approved knowledge topics
async fn knowledge_topics(State(state): State<AppState>) -> impl IntoResponse {
    let categories: Vec<String> = state.knowledge_base.documents.keys().cloned().collect();
    Json(json!({
        "institution": "Hindustani Covenant Church High School, Solapur",
        "categories": categories,
        "summary": state.knowledge_base.summary_markdown
    }))
}

/// POST /api/validate - Validate an edited draft
async fn validate_draft_route(
    State(state): State<AppState>,
    Json(mut draft): Json<AdmissionDraftResponse>,
) -> impl IntoResponse {
    let (intent, _conf, _parent, student) = IntentClassifier::classify_and_extract(
        &draft.response_draft,
        &[],
        draft.extracted_parent_details.name.as_deref(),
        draft.extracted_parent_details.phone.as_deref(),
    );

    let slice = state.knowledge_base.retrieve_slice(
        &intent,
        student.grade_applying_for.as_deref(),
        &draft.response_draft,
    );

    let query_copy = draft.response_draft.clone();
    SafetyValidator::validate_and_harden(&mut draft, &slice, &query_copy);

    Json(json!({
        "validated_draft": draft,
        "is_safe": !draft.escalation_required
    }))
}

/// POST /api/triage-inbound - Automation #9 inbound WhatsApp message triage
async fn triage_inbound_route(
    State(state): State<AppState>,
    Json(payload): Json<TriageInboundRequest>,
) -> impl IntoResponse {
    let model = payload
        .model
        .as_deref()
        .filter(|m| !m.trim().is_empty())
        .unwrap_or(&state.config.default_model);

    let result = state.ollama_client.triage_inbound(model, &payload).await;
    (StatusCode::OK, Json(result))
}

/// POST /api/result-copilot - Factual, empathetic parent reply drafting from student marks
async fn result_copilot_route(
    State(state): State<AppState>,
    Json(payload): Json<ResultCopilotRequest>,
) -> impl IntoResponse {
    let model = payload
        .model
        .as_deref()
        .filter(|m| !m.trim().is_empty())
        .unwrap_or(&state.config.default_model);

    let query = payload.parent_message.as_deref().unwrap_or("");
    let slice = state.knowledge_base.retrieve_slice(
        &AdmissionIntent::SchoolTimings,
        payload.class_label.as_deref(),
        query,
    );

    let result = state
        .ollama_client
        .generate_result_copilot(model, &payload, &slice)
        .await;

    (StatusCode::OK, Json(result))
}

#[cfg(test)]
mod tests {
    use super::*;
    use axum::body::{to_bytes, Body};
    use axum::http::Method;
    use tower::Service;

    fn test_state(secret: Option<&str>) -> (AppState, Vec<tokio::task::JoinHandle<()>>) {
        let mut cfg = AppConfig::default();
        cfg.ollama_url = "http://127.0.0.1:9".to_string();
        cfg.ai_service_secret = secret.map(|s| s.to_string());
        let kb = Arc::new(KnowledgeBase::default());
        let ollama = Arc::new(OllamaClient::new(cfg.ollama_url.clone(), 5));
        let (queue, handles) = InferenceQueue::new(4, 1, kb.clone(), ollama.clone(), cfg.default_model.clone());
        (
            AppState {
                config: cfg,
                knowledge_base: kb,
                ollama_client: ollama,
                queue,
            },
            handles,
        )
    }

    async fn call_app(app: &mut Router, req: axum::http::Request<Body>) -> Response {
        std::future::poll_fn(|cx| Service::<axum::http::Request<Body>>::poll_ready(app, cx))
            .await
            .unwrap();
        Service::<axum::http::Request<Body>>::call(app, req)
            .await
            .unwrap()
    }

    #[tokio::test]
    async fn secret_middleware_and_no_permissive_cors() {
        let (state, handles) = test_state(Some("top-secret-123"));
        let mut app = create_router(state);

        // 1. Missing secret -> 401
        let req_missing = axum::http::Request::builder()
            .method(Method::GET)
            .uri("/api/queue-status")
            .header("Origin", "https://evil.example")
            .body(Body::empty())
            .unwrap();
        let resp = call_app(&mut app, req_missing).await;
        assert_eq!(resp.status(), StatusCode::UNAUTHORIZED);
        assert!(resp.headers().get("access-control-allow-origin").is_none());

        // 2. Wrong secret -> 401
        let req_wrong = axum::http::Request::builder()
            .method(Method::GET)
            .uri("/api/queue-status")
            .header("X-AI-Service-Secret", "wrong")
            .body(Body::empty())
            .unwrap();
        let resp = call_app(&mut app, req_wrong).await;
        assert_eq!(resp.status(), StatusCode::UNAUTHORIZED);

        // 3. Valid secret -> 200 and no permissive CORS header
        let req_ok = axum::http::Request::builder()
            .method(Method::GET)
            .uri("/api/queue-status")
            .header("Origin", "https://evil.example")
            .header("X-AI-Service-Secret", "top-secret-123")
            .body(Body::empty())
            .unwrap();
        let resp = call_app(&mut app, req_ok).await;
        assert_eq!(resp.status(), StatusCode::OK);
        assert!(resp.headers().get("access-control-allow-origin").is_none());

        for h in handles {
            h.abort();
        }
    }

    #[tokio::test]
    async fn triage_and_result_copilot_endpoints_work_without_ollama() {
        let (state, handles) = test_state(Some("secret-xyz"));
        let mut app = create_router(state);

        let triage_body = serde_json::to_string(&json!({
            "chat_id": 12,
            "message_id": 55,
            "student_name": "Riya Patil",
            "student_class": "Grade 6",
            "message_text": "Paid fees via UPI today, please share receipt"
        }))
        .unwrap();

        let req_triage = axum::http::Request::builder()
            .method(Method::POST)
            .uri("/api/triage-inbound")
            .header("Content-Type", "application/json")
            .header("X-AI-Service-Secret", "secret-xyz")
            .body(Body::from(triage_body))
            .unwrap();

        let resp = call_app(&mut app, req_triage).await;
        assert_eq!(resp.status(), StatusCode::OK);
        let bytes = to_bytes(resp.into_body(), 1024 * 64).await.unwrap();
        let parsed: serde_json::Value = serde_json::from_slice(&bytes).unwrap();
        assert_eq!(parsed["signal_type"], "fee_claim");

        let copilot_body = serde_json::to_string(&json!({
            "chat_id": 12,
            "student_name": "Riya Patil",
            "roll_no": "14",
            "class_label": "Grade 6 A",
            "exam_label": "Semester 1",
            "subjects": [
                { "subject_name": "Mathematics", "obtained": 42, "max": 50, "grade": "A1" },
                { "subject_name": "Science", "obtained": 18, "max": 50, "grade": "C2" }
            ],
            "overall_obtained": 60,
            "overall_max": 100,
            "percentage": 60.0
        }))
        .unwrap();

        let req_copilot = axum::http::Request::builder()
            .method(Method::POST)
            .uri("/api/result-copilot")
            .header("Content-Type", "application/json")
            .header("X-AI-Service-Secret", "secret-xyz")
            .body(Body::from(copilot_body))
            .unwrap();

        let resp2 = call_app(&mut app, req_copilot).await;
        assert_eq!(resp2.status(), StatusCode::OK);
        let bytes2 = to_bytes(resp2.into_body(), 1024 * 64).await.unwrap();
        let parsed2: serde_json::Value = serde_json::from_slice(&bytes2).unwrap();
        assert!(parsed2["draft_reply"].as_str().unwrap().contains("Riya Patil"));
        assert_eq!(parsed2["strong_subjects"][0], "Mathematics (42/50)");
        assert_eq!(parsed2["weak_subjects"][0], "Science (18/50)");

        for h in handles {
            h.abort();
        }
    }
}
