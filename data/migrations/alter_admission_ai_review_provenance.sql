-- Additive: analysis provenance (provider/model/host). Never stores API keys.
-- Rollback:
--   ALTER TABLE admission_ai_review_drafts
--     DROP COLUMN analysis_engine, DROP COLUMN analysis_provider,
--     DROP COLUMN analysis_model, DROP COLUMN analysis_endpoint_host;

ALTER TABLE `admission_ai_review_drafts`
  ADD COLUMN IF NOT EXISTS `analysis_engine` VARCHAR(20) NULL DEFAULT 'local' AFTER `model_used`,
  ADD COLUMN IF NOT EXISTS `analysis_provider` VARCHAR(40) NULL DEFAULT NULL AFTER `analysis_engine`,
  ADD COLUMN IF NOT EXISTS `analysis_model` VARCHAR(120) NULL DEFAULT NULL AFTER `analysis_provider`,
  ADD COLUMN IF NOT EXISTS `analysis_endpoint_host` VARCHAR(255) NULL DEFAULT NULL AFTER `analysis_model`;
