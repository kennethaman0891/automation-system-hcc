use std::sync::Arc;
use clap::{Parser, Subcommand};
use tracing::{info, Level};
use tracing_subscriber::FmtSubscriber;

mod config;
mod types;
mod knowledge;
mod intent;
mod validator;
mod ollama;
mod queue;
mod benchmark;
mod routes;

use config::AppConfig;
use knowledge::KnowledgeBase;
use ollama::OllamaClient;
use queue::InferenceQueue;
use routes::{create_router, AppState};
use benchmark::BenchmarkSuite;

#[derive(Parser)]
#[command(name = "admission-ai-service")]
#[command(about = "Local AI Admission Enquiry Assistant for HCC School WhatsApp Hub")]
struct Cli {
    #[command(subcommand)]
    command: Option<Commands>,

    #[arg(long, default_value = "127.0.0.1")]
    host: Option<String>,

    #[arg(short, long, default_value_t = 8085)]
    port: u16,

    #[arg(short, long)]
    model: Option<String>,
}

#[derive(Subcommand)]
enum Commands {
    /// Start the HTTP API server (default)
    Serve,
    /// Run model benchmarking for 8GB RAM Windows targets
    Benchmark {
        #[arg(short, long, default_values_t = vec!["llama3.2:1b".to_string(), "llama3.2:latest".to_string()])]
        models: Vec<String>,
    },
    /// Test processing a single parent inquiry directly in the console
    TestPrompt {
        #[arg(short, long)]
        message: String,
        #[arg(short, long)]
        grade: Option<String>,
    },
}

#[tokio::main]
async fn main() -> Result<(), Box<dyn std::error::Error>> {
    let subscriber = FmtSubscriber::builder()
        .with_max_level(Level::INFO)
        .finish();
    tracing::subscriber::set_global_default(subscriber)
        .expect("setting default subscriber failed");

    let cli = Cli::parse();
    let mut config = AppConfig::default();

    if let Some(host) = cli.host {
        config.host = host;
    }
    config.port = cli.port;
    if let Some(model) = cli.model {
        config.default_model = model;
    }

    info!("Initializing HCC Admission AI Assistant Service");
    info!("Target Platform: Windows Intel CPU, 8 GB RAM, 500 GB Storage");
    info!("Default Ollama Model: {}", config.default_model);

    // 1. Load approved school knowledge base
    let knowledge_base = Arc::new(KnowledgeBase::load_from_dir(&config.knowledge_dir));

    // 2. Initialize Ollama client
    let ollama_client = Arc::new(OllamaClient::new(
        config.ollama_url.clone(),
        config.request_timeout_secs,
    ));

    match cli.command.unwrap_or(Commands::Serve) {
        Commands::Serve => {
            // 3. Initialize single-inference queue
            info!(
                "Inference concurrency: {} (set AI_INFERENCE_CONCURRENCY=1 on 8 GB RAM)",
                config.inference_concurrency
            );
            let (queue, _workers) = InferenceQueue::new(
                config.max_queue_depth,
                config.inference_concurrency,
                knowledge_base.clone(),
                ollama_client.clone(),
                config.default_model.clone(),
            );

            let app_state = AppState {
                config: config.clone(),
                knowledge_base: knowledge_base.clone(),
                ollama_client: ollama_client.clone(),
                queue,
            };

            let app = create_router(app_state);
            let addr = format!("{}:{}", config.host, config.port);
            let listener = tokio::net::TcpListener::bind(&addr).await?;

            info!("🚀 Server running on http://{}", addr);
            info!("Single-inference queue ready. Waiting for parent enquiries...");

            axum::serve(listener, app).await?;
        }

        Commands::Benchmark { models } => {
            info!("Running benchmark suite on candidate models: {:?}", models);
            let results = BenchmarkSuite::run_benchmark(
                ollama_client.clone(),
                knowledge_base.clone(),
                &models,
            )
            .await;

            println!("\n==========================================================================================");
            println!("   HCC ADMISSION AI ASSISTANT — MODEL BENCHMARK REPORT (8GB RAM WINDOWS TARGET)   ");
            println!("==========================================================================================");
            for r in &results {
                println!("\nModel: {}", r.model_name);
                println!("  • Tests: {}/{} passed", r.passed_tests, r.total_tests);
                println!("  • Avg Latency: {} ms", r.avg_latency_ms);
                println!("  • Tokens/sec: {:.1}", r.tokens_per_sec);
                println!("  • JSON Compliance: {:.1}%", r.json_compliance_pct);
                println!("  • Factual Accuracy: {:.1}%", r.factual_accuracy_pct);
                println!("  • Memory Footprint: {} MB", r.memory_footprint_mb);
                println!("  • Recommended for 8GB RAM Windows: {}", if r.recommended_for_8gb_win { "YES (Optimal)" } else { "NO / High Risk" });
                println!("  • Notes: {}", r.notes);
            }
            println!("==========================================================================================\n");
        }

        Commands::TestPrompt { message, grade: _ } => {
            println!("Testing inquiry: \"{}\"", message);
            let (queue, _workers) = InferenceQueue::new(
                10,
                config.inference_concurrency,
                knowledge_base.clone(),
                ollama_client.clone(),
                config.default_model.clone(),
            );

            let req = types::EnquiryRequest {
                chat_id: 1,
                message_id: None,
                parent_phone: Some("+910000000000".to_string()),
                parent_name: Some("Test Parent".to_string()),
                current_message: message,
                conversation_context: vec![],
                model: None,
            };

            let resp = queue.submit(req).await?;
            println!("\nResult:\n{}", serde_json::to_string_pretty(&resp)?);
        }
    }

    Ok(())
}
