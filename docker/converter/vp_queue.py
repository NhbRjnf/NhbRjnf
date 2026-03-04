import os
import redis

def get_redis() -> redis.Redis:
    host = os.getenv("VP_3D_REDIS_HOST", "redis")
    port = int(os.getenv("VP_3D_REDIS_PORT", "6379"))
    return redis.Redis(host=host, port=port, decode_responses=True)

def queue_key() -> str:
    return os.getenv("VP_3D_QUEUE_KEY", "vp:3d:jobs")

def enqueue_job(job_id: str) -> None:
    r = get_redis()
    r.lpush(queue_key(), job_id)

def blocking_pop(timeout_sec: int = 30):
    r = get_redis()
    # BRPOP returns (key, value) or None
    return r.brpop(queue_key(), timeout=timeout_sec)
