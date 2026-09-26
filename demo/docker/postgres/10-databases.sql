-- Runs once, on an empty data volume. The image creates POSTGRES_DB
-- (vector_admin, Vector's own tables); the demo's support desk lives in
-- a second database on the same server, the named connection "support".
CREATE DATABASE vector_support OWNER vector;
