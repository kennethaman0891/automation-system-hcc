use mysql::prelude::*;
use mysql::*;
use std::env;
use std::fs;
use std::path::Path;

#[derive(Clone, Debug)]
pub struct DbConfig {
    pub host: String,
    pub port: u16,
    pub user: String,
    pub pass: String,
    pub db_name: String,
    pub socket: Option<String>,
}

fn parse_env_file_value(path: &Path, key: &str) -> Option<String> {
    let content = fs::read_to_string(path).ok()?;
    for line in content.lines() {
        let trimmed = line.trim();
        if trimmed.is_empty() || trimmed.starts_with('#') {
            continue;
        }
        if let Some((k, v)) = trimmed.split_once('=') {
            if k.trim() == key {
                let cleaned = v
                    .trim()
                    .trim_matches('"')
                    .trim_matches('\'')
                    .trim()
                    .to_string();
                if !cleaned.is_empty() {
                    return Some(cleaned);
                }
            }
        }
    }
    None
}

fn parse_php_define_or_array(path: &Path, key: &str) -> Option<String> {
    let content = fs::read_to_string(path).ok()?;
    for line in content.lines() {
        let trimmed = line.trim();
        if trimmed.starts_with("//") || trimmed.starts_with('#') {
            continue;
        }
        if !trimmed.contains(key) {
            continue;
        }
        // Match PHP array syntax: 'DB_PASS' => 'value'
        if let Some((_, rhs)) = trimmed.split_once("=>") {
            let candidate = rhs
                .trim()
                .trim_end_matches(',')
                .trim_matches('\'')
                .trim_matches('"')
                .trim()
                .to_string();
            if !candidate.is_empty() {
                return Some(candidate);
            }
        }
        // Match PHP define syntax: define('DB_PASS', 'value');
        if let Some(idx) = trimmed.find(',') {
            let after_comma = &trimmed[idx + 1..];
            let candidate = after_comma
                .trim()
                .trim_end_matches(';')
                .trim_end_matches(')')
                .trim()
                .trim_matches('\'')
                .trim_matches('"')
                .trim()
                .to_string();
            if !candidate.is_empty() {
                return Some(candidate);
            }
        }
    }
    None
}

pub fn lookup_local_config_value(key: &str) -> Option<String> {
    if let Ok(val) = env::var(key) {
        let trimmed = val.trim().to_string();
        if !trimmed.is_empty() {
            return Some(trimmed);
        }
    }

    let env_candidates = [
        Path::new("automation/.env"),
        Path::new(".env"),
        Path::new("../../automation/.env"),
        Path::new("../.env"),
    ];
    for p in env_candidates {
        if p.exists() {
            if let Some(v) = parse_env_file_value(p, key) {
                return Some(v);
            }
        }
    }

    let php_candidates = [
        Path::new("config/env.php"),
        Path::new("../config/env.php"),
        Path::new("../../config/env.php"),
    ];
    for p in php_candidates {
        if p.exists() {
            if let Some(v) = parse_php_define_or_array(p, key) {
                return Some(v);
            }
        }
    }

    None
}

impl DbConfig {
    /// Load configuration without any hardcoded credential fallback.
    pub fn from_env_without_pass(cli_pass: Option<&str>) -> std::result::Result<Self, String> {
        let host = env::var("DB_HOST")
            .ok()
            .filter(|v| !v.trim().is_empty())
            .or_else(|| lookup_local_config_value("DB_HOST"))
            .unwrap_or_else(|| "127.0.0.1".to_string());

        let port = env::var("DB_PORT")
            .ok()
            .and_then(|p| p.parse().ok())
            .or_else(|| lookup_local_config_value("DB_PORT").and_then(|p| p.parse().ok()))
            .unwrap_or(3306);

        let user = env::var("DB_USER")
            .ok()
            .filter(|v| !v.trim().is_empty())
            .or_else(|| lookup_local_config_value("DB_USER"))
            .unwrap_or_else(|| "hcc_app".to_string());

        let db_name = env::var("DB_NAME")
            .ok()
            .filter(|v| !v.trim().is_empty())
            .or_else(|| lookup_local_config_value("DB_NAME"))
            .unwrap_or_else(|| "hcc_admin".to_string());

        // Windows has no Unix socket. A copied config/env.php still names the
        // macOS XAMPP socket; using it would skip the TCP fallback.
        let socket = if cfg!(windows) {
            None
        } else {
            env::var("DB_SOCKET")
                .ok()
                .filter(|v| !v.trim().is_empty())
                .or_else(|| lookup_local_config_value("DB_SOCKET"))
                .or_else(|| {
                    let default_sock = "/Applications/XAMPP/xamppfiles/var/mysql/mysql.sock";
                    if Path::new(default_sock).exists() {
                        Some(default_sock.to_string())
                    } else {
                        None
                    }
                })
        };

        let pass = cli_pass
            .map(|s| s.trim().to_string())
            .filter(|s| !s.is_empty())
            .or_else(|| {
                env::var("DB_PASS")
                    .ok()
                    .map(|s| s.trim().to_string())
                    .filter(|s| !s.is_empty())
            })
            .or_else(|| lookup_local_config_value("DB_PASS"))
            .ok_or_else(|| {
                "DB_PASS environment variable is required (no hardcoded fallback permitted)".to_string()
            })?;

        Ok(Self {
            host,
            port,
            user,
            pass,
            db_name,
            socket,
        })
    }

    pub fn try_from_env() -> std::result::Result<Self, String> {
        Self::from_env_without_pass(None)
    }

    pub fn require_pass(&self) -> std::result::Result<(), String> {
        if self.pass.trim().is_empty() {
            return Err("DB_PASS environment variable is required".to_string());
        }
        Ok(())
    }
}

pub fn create_pool(cfg: &DbConfig) -> std::result::Result<Pool, String> {
    cfg.require_pass()?;

    // Try socket if provided
    if let Some(ref sock_path) = cfg.socket {
        if Path::new(sock_path).exists() {
            let mut opts = OptsBuilder::new();
            opts = opts
                .user(Some(&cfg.user))
                .pass(Some(&cfg.pass))
                .db_name(Some(&cfg.db_name))
                .socket(Some(sock_path));

            if let Ok(pool) = Pool::new(opts) {
                if let Ok(mut conn) = pool.get_conn() {
                    conn.query_drop("SELECT 1")
                        .map_err(|e| format!("Socket DB ping failed: {}", e))?;
                    return Ok(pool);
                }
            }
        }
    }

    // Fallback to TCP (127.0.0.1:3306)
    let mut opts = OptsBuilder::new();
    opts = opts
        .ip_or_hostname(Some(if cfg.host == "localhost" {
            "127.0.0.1"
        } else {
            &cfg.host
        }))
        .tcp_port(cfg.port)
        .user(Some(&cfg.user))
        .pass(Some(&cfg.pass))
        .db_name(Some(&cfg.db_name));

    let pool = Pool::new(opts).map_err(|e| format!("Failed to create MySQL pool: {}", e))?;
    let mut conn = pool
        .get_conn()
        .map_err(|e| format!("Failed to connect to MySQL: {}", e))?;
    conn.query_drop("SELECT 1")
        .map_err(|e| format!("MySQL ping failed: {}", e))?;
    Ok(pool)
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn empty_db_pass_fails_closed() {
        let cfg = DbConfig {
            host: "127.0.0.1".to_string(),
            port: 3306,
            user: "hcc_app".to_string(),
            pass: "".to_string(),
            db_name: "hcc_admin".to_string(),
            socket: None,
        };
        let err = create_pool(&cfg).unwrap_err();
        assert!(err.contains("DB_PASS"));
    }

    #[test]
    fn parses_php_env_array_line() {
        let dir = env::temp_dir();
        let path = dir.join("hcc_test_env.php");
        fs::write(&path, "<?php\nreturn [\n    'DB_PASS' => 'my-local-secret',\n];\n").unwrap();
        assert_eq!(
            parse_php_define_or_array(&path, "DB_PASS"),
            Some("my-local-secret".to_string())
        );
        let _ = fs::remove_file(&path);
    }
}
