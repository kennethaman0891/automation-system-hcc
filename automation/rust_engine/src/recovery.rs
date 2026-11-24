use mysql::prelude::*;
use mysql::*;

pub fn recover_interrupted_jobs(pool: &Pool, worker_id: &str) -> Result<usize> {
    let mut conn = pool.get_conn()?;
    let mut tx = conn.start_transaction(TxOpts::default())?;

    // Only recover jobs left in 'processing' for longer than 5 minutes so concurrent workers aren't interrupted
    let stuck_job_ids: Vec<u64> = tx.query_map(
        "SELECT id FROM automation_jobs
         WHERE status = 'processing'
           AND (locked_at < NOW() - INTERVAL 5 MINUTE OR locked_at IS NULL)",
        |id: u64| id,
    )?;

    if stuck_job_ids.is_empty() {
        tx.commit()?;
        return Ok(0);
    }

    let count = stuck_job_ids.len();
    eprintln!(
        "[Recovery] Found {} stale interrupted job(s) (>5m in processing). Recovering to pending queue...",
        count
    );

    tx.query_drop(
        "UPDATE automation_jobs
         SET status = 'pending', locked_at = NULL, locked_by = NULL
         WHERE status = 'processing'
           AND (locked_at < NOW() - INTERVAL 5 MINUTE OR locked_at IS NULL)",
    )?;

    for job_id in stuck_job_ids {
        tx.exec_drop(
            "INSERT INTO automation_job_history (
                job_id, job_type, attempt_number, status, worker_id,
                execution_duration_ms, output_summary, executed_at
            ) VALUES (
                ?, 'recovery', 1, 'recovered', ?,
                0, 'Job recovered after stale processing lock (>5 minutes)', NOW()
            )",
            (job_id, worker_id),
        )?;
    }

    tx.commit()?;
    eprintln!("[Recovery] Successfully recovered {} job(s).", count);
    Ok(count)
}
