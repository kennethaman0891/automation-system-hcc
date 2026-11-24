mod db;
mod models;
mod processor;
mod recovery;
mod scheduler;

use chrono::Local;
use db::{create_pool, DbConfig};
use models::EngineStats;
use mysql::prelude::*;
use std::env;
use std::process;
use std::thread;
use std::time::Duration;

const WORKER_VERSION: &str = "1.0.0-rust";

fn print_help() {
    println!(
        "HCC Automation Engine — High Performance Durable Job Queue v{}",
        WORKER_VERSION
    );
    println!("Usage: hcc-automation-engine [OPTIONS]");
    println!();
    println!("Options:");
    println!("  --once           Run single cycle (recover + enqueue due + process jobs) and exit");
    println!("  --daemon         Run continuously in background (default loop 5s)");
    println!("  --status         Output JSON queue and follow-up metrics");
    println!("  --recover        Recover stale jobs left in 'processing' state (>5m)");
    println!("  --enqueue-due    Scan enquiries/inbound signals/briefing and enqueue due jobs");
    println!("  --test-retry     Enqueue a test failing job to verify retry backoff mechanism");
    println!("  --db-host <HOST> MySQL Host (or DB_HOST env)");
    println!("  --db-port <PORT> MySQL Port (or DB_PORT env)");
    println!("  --db-user <USER> MySQL User (or DB_USER env)");
    println!("  --db-pass <PASS> MySQL Password (prefer DB_PASS env)");
    println!("  --db-name <NAME> MySQL Database Name (or DB_NAME env)");
    println!("  --db-socket <SOCK> MySQL Unix Socket path (or DB_SOCKET env)");
    println!("  --help, -h       Display this help message");
}

fn parse_config(args: &[String]) -> std::result::Result<(DbConfig, String), String> {
    let mut cli_pass: Option<String> = None;
    let mut cli_host: Option<String> = None;
    let mut cli_port: Option<u16> = None;
    let mut cli_user: Option<String> = None;
    let mut cli_db_name: Option<String> = None;
    let mut cli_socket: Option<String> = None;
    let mut mode = "once".to_string();

    let mut i = 1;
    while i < args.len() {
        match args[i].as_str() {
            "--once" => mode = "once".to_string(),
            "--daemon" => mode = "daemon".to_string(),
            "--status" => mode = "status".to_string(),
            "--recover" => mode = "recover".to_string(),
            "--enqueue-due" => mode = "enqueue-due".to_string(),
            "--test-retry" => mode = "test-retry".to_string(),
            "--db-host" if i + 1 < args.len() => {
                i += 1;
                cli_host = Some(args[i].clone());
            }
            "--db-port" if i + 1 < args.len() => {
                i += 1;
                if let Ok(p) = args[i].parse() {
                    cli_port = Some(p);
                }
            }
            "--db-user" if i + 1 < args.len() => {
                i += 1;
                cli_user = Some(args[i].clone());
            }
            "--db-pass" if i + 1 < args.len() => {
                i += 1;
                cli_pass = Some(args[i].clone());
            }
            "--db-name" if i + 1 < args.len() => {
                i += 1;
                cli_db_name = Some(args[i].clone());
            }
            "--db-socket" if i + 1 < args.len() => {
                i += 1;
                cli_socket = Some(args[i].clone());
            }
            "--help" | "-h" => {
                print_help();
                process::exit(0);
            }
            _ => {}
        }
        i += 1;
    }

    let mut cfg = DbConfig::from_env_without_pass(cli_pass.as_deref())?;
    if let Some(h) = cli_host {
        cfg.host = h;
    }
    if let Some(p) = cli_port {
        cfg.port = p;
    }
    if let Some(u) = cli_user {
        cfg.user = u;
    }
    if let Some(d) = cli_db_name {
        cfg.db_name = d;
    }
    if let Some(s) = cli_socket {
        cfg.socket = Some(s);
    }

    Ok((cfg, mode))
}

fn fetch_stats(pool: &mysql::Pool) -> mysql::Result<EngineStats> {
    let mut conn = pool.get_conn()?;

    let pending_jobs: u64 = conn
        .query_first("SELECT COUNT(*) FROM automation_jobs WHERE status = 'pending'")?
        .unwrap_or(0);
    let processing_jobs: u64 = conn
        .query_first("SELECT COUNT(*) FROM automation_jobs WHERE status = 'processing'")?
        .unwrap_or(0);
    let completed_jobs: u64 = conn
        .query_first("SELECT COUNT(*) FROM automation_jobs WHERE status = 'completed'")?
        .unwrap_or(0);
    let failed_jobs: u64 = conn
        .query_first("SELECT COUNT(*) FROM automation_jobs WHERE status = 'failed'")?
        .unwrap_or(0);

    let total_enquiries: u64 = conn
        .query_first("SELECT COUNT(*) FROM admission_enquiries")?
        .unwrap_or(0);
    let today_followups: u64 = conn
        .query_first(
            "SELECT COUNT(*) FROM admission_enquiries WHERE next_followup_date = CURDATE() AND is_completed = 0 AND is_cancelled = 0",
        )?
        .unwrap_or(0);
    let overdue_followups: u64 = conn
        .query_first(
            "SELECT COUNT(*) FROM admission_enquiries WHERE next_followup_date < CURDATE() AND is_completed = 0 AND is_cancelled = 0",
        )?
        .unwrap_or(0);
    let upcoming_followups: u64 = conn
        .query_first(
            "SELECT COUNT(*) FROM admission_enquiries WHERE next_followup_date > CURDATE() AND is_completed = 0 AND is_cancelled = 0",
        )?
        .unwrap_or(0);
    let high_priority_enquiries: u64 = conn
        .query_first(
            "SELECT COUNT(*) FROM admission_enquiries WHERE priority IN ('high', 'urgent') AND is_completed = 0 AND is_cancelled = 0",
        )?
        .unwrap_or(0);
    let awaiting_responses: u64 = conn
        .query_first(
            "SELECT COUNT(*) FROM admission_enquiries WHERE followup_category = 'First response pending' AND is_completed = 0 AND is_cancelled = 0",
        )?
        .unwrap_or(0);
    let interested_parents: u64 = conn
        .query_first(
            "SELECT COUNT(*) FROM admission_enquiries WHERE followup_category = 'Parent interested' AND is_completed = 0 AND is_cancelled = 0",
        )?
        .unwrap_or(0);
    let completed_admissions: u64 = conn
        .query_first(
            "SELECT COUNT(*) FROM admission_enquiries WHERE followup_category = 'Completed admission' OR is_completed = 1",
        )?
        .unwrap_or(0);

    Ok(EngineStats {
        pending_jobs,
        processing_jobs,
        completed_jobs,
        failed_jobs,
        total_enquiries,
        today_followups,
        overdue_followups,
        upcoming_followups,
        high_priority_enquiries,
        awaiting_responses,
        interested_parents,
        completed_admissions,
        last_run_timestamp: Local::now().to_rfc3339(),
    })
}

fn enqueue_test_retry_job(pool: &mysql::Pool) -> mysql::Result<()> {
    let mut conn = pool.get_conn()?;
    let dedup_key = format!("test_retry_{}", Local::now().timestamp_millis());
    let payload = r#"{"action":"test_retry","should_fail":true,"note":"Verification of retry backoff"}"#;

    conn.exec_drop(
        "INSERT INTO automation_jobs (job_type, payload, dedup_key, status, priority, max_attempts, scheduled_at)
         VALUES ('custom_test_job', ?, ?, 'pending', 50, 3, NOW())",
        (payload, &dedup_key),
    )?;

    println!("Enqueued test retry job with dedup_key: {}", dedup_key);
    Ok(())
}

fn enqueue_all(pool: &mysql::Pool) -> mysql::Result<usize> {
    let r = scheduler::scan_and_enqueue_reminders(pool)?;
    let t = scheduler::scan_and_enqueue_inbound_triage(pool)?;
    let b = scheduler::schedule_daily_briefing(pool)?;
    Ok(r + t + b)
}

fn main() {
    let args: Vec<String> = env::args().collect();
    let (cfg, mode) = match parse_config(&args) {
        Ok(res) => res,
        Err(e) => {
            eprintln!("[Error] Configuration error: {}", e);
            process::exit(1);
        }
    };

    let worker_id = format!("rust_worker_{}_{}", process::id(), WORKER_VERSION);

    let pool = match create_pool(&cfg) {
        Ok(p) => p,
        Err(e) => {
            eprintln!("[Error] Failed to connect to database: {}", e);
            process::exit(1);
        }
    };

    match mode.as_str() {
        "status" => match fetch_stats(&pool) {
            Ok(stats) => {
                println!("{}", serde_json::to_string_pretty(&stats).unwrap());
                process::exit(0);
            }
            Err(e) => {
                eprintln!("[Error] Failed to fetch stats: {}", e);
                process::exit(1);
            }
        },
        "recover" => match recovery::recover_interrupted_jobs(&pool, &worker_id) {
            Ok(recovered) => {
                println!("Recovered {} interrupted jobs.", recovered);
                process::exit(0);
            }
            Err(e) => {
                eprintln!("[Error] Recovery failed: {}", e);
                process::exit(1);
            }
        },
        "enqueue-due" => match enqueue_all(&pool) {
            Ok(enqueued) => {
                println!("Enqueued {} job(s).", enqueued);
                process::exit(0);
            }
            Err(e) => {
                eprintln!("[Error] Enqueue failed: {}", e);
                process::exit(1);
            }
        },
        "test-retry" => {
            if let Err(e) = enqueue_test_retry_job(&pool) {
                eprintln!("[Error] Failed to enqueue test retry job: {}", e);
                process::exit(1);
            }
            if let Err(e) = recovery::recover_interrupted_jobs(&pool, &worker_id) {
                eprintln!("[Warn] Recovery error during test-retry: {}", e);
            }
            if let Err(e) = processor::process_pending_jobs(&pool, &worker_id, 10) {
                eprintln!("[Warn] Processor error during test-retry: {}", e);
            }
            println!("Test retry job executed. Check automation_job_history for retry log.");
            process::exit(0);
        }
        "once" => {
            let recovered = match recovery::recover_interrupted_jobs(&pool, &worker_id) {
                Ok(n) => n,
                Err(e) => {
                    eprintln!("[Error] Recovery step failed: {}", e);
                    process::exit(1);
                }
            };

            let enqueued = match enqueue_all(&pool) {
                Ok(n) => n,
                Err(e) => {
                    eprintln!("[Error] Scheduler step failed: {}", e);
                    process::exit(1);
                }
            };

            let processed = match processor::process_pending_jobs(&pool, &worker_id, 50) {
                Ok(n) => n,
                Err(e) => {
                    eprintln!("[Error] Processor step failed: {}", e);
                    process::exit(1);
                }
            };

            println!(
                r#"{{"success":true,"mode":"once","worker_id":"{}","recovered":{},"enqueued":{},"processed":{},"timestamp":"{}"}}"#,
                worker_id,
                recovered,
                enqueued,
                processed,
                Local::now().to_rfc3339()
            );
            process::exit(0);
        }
        "daemon" => {
            println!(
                "[Daemon] HCC Automation Engine daemon started (Worker ID: {}). Polling queue every 5 seconds...",
                worker_id
            );

            if let Err(e) = recovery::recover_interrupted_jobs(&pool, &worker_id) {
                eprintln!("[Daemon][Error] Initial recovery failed: {}", e);
            }

            loop {
                if let Err(e) = enqueue_all(&pool) {
                    eprintln!("[Daemon][Error] Scheduler tick failed: {}", e);
                }
                if let Err(e) = processor::process_pending_jobs(&pool, &worker_id, 20) {
                    eprintln!("[Daemon][Error] Processor tick failed: {}", e);
                }
                thread::sleep(Duration::from_secs(5));
            }
        }
        _ => {
            print_help();
            process::exit(1);
        }
    }
}
