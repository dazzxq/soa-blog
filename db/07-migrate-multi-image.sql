-- =============================================================================
-- Phase: multi-image posts. Idempotent + NON-destructive.
-- Adds posts.images (JSON array of up to 9 image URLs). image_url keeps mirroring
-- images[0] for back-compat with single-image consumers. Safe to re-run.
-- =============================================================================
USE proconnect_feed;

ALTER TABLE posts ADD COLUMN IF NOT EXISTS images JSON NULL AFTER image_url;
