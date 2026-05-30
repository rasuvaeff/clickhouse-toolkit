CREATE TABLE IF NOT EXISTS events
(
    id         UInt64,
    type       String,
    user_id    UInt32,
    payload    String,
    created_at DateTime
)
ENGINE = MergeTree()
ORDER BY (created_at, id)
