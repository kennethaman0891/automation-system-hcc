use std::path::PathBuf;

#[derive(Debug, Clone)]
pub struct AppConfig {
    pub host: String,
    pub port: u16,
    pub ollama_url: String,
    pub default_model: String,
    pub knowledge_dir: PathBuf,
    pub max_queue_depth: usize,
    pub request_timeout_secs: u64,
    pub inference_concurrency: usize,
    pub ai_service_secret: Option<String>,
}

impl Default for AppConfig {
    fn default() -> Self {
        // Look for knowledge directory relative to executable or workspace
        let candidate_paths = [
            PathBuf::from("../../knowledge/approved"),
            PathBuf::from("knowledge/approved"),
            PathBuf::from("/Applications/XAMPP/xamppfiles/htdocs/hcc/knowledge/approved"),
            PathBuf::from("C:\\xampp\\htdocs\\hcc\\knowledge\\approved"),
        ];

        let knowledge_dir = std::env::var("KNOWLEDGE_DIR")
            .ok()
            .map(PathBuf::from)
            .filter(|p| p.exists())
            .or_else(|| candidate_paths.into_iter().find(|p| p.exists()))
            .unwrap_or_else(|| PathBuf::from("knowledge/approved"));

        let host = std::env::var("AI_SERVICE_HOST").unwrap_or_else(|_| "127.0.0.1".to_string());
        let port = std::env::var("AI_SERVICE_PORT")
            .ok()
            .and_then(|p| p.parse().ok())
            .unwrap_or(8085);

        let ollama_url = std::env::var("OLLAMA_HOST")
            .unwrap_or_else(|_| "http://127.0.0.1:11434".to_string());

        let default_model = std::env::var("AI_MODEL")
            .unwrap_or_else(|_| "llama3.2:1b".to_string());

        let inference_concurrency = std::env::var("AI_INFERENCE_CONCURRENCY")
            .ok()
            .and_then(|v| v.parse::<usize>().ok())
            .unwrap_or(1)
            .clamp(1, 4);

        let request_timeout_secs = std::env::var("AI_REQUEST_TIMEOUT_SECS")
            .ok()
            .and_then(|v| v.parse::<u64>().ok())
            .unwrap_or(60)
            .clamp(5, 300);

        let ai_service_secret = std::env::var("AI_SERVICE_SECRET")
            .ok()
            .map(|s| s.trim().to_string())
            .filter(|s| !s.is_empty());

        Self {
            host,
            port,
            ollama_url,
            default_model,
            knowledge_dir,
            max_queue_depth: 100,
            request_timeout_secs,
            inference_concurrency,
            ai_service_secret,
        }
    }
}
