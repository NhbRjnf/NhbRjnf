import os
import redis

# --------------------------------------------------
# REDIS CONNECTION
# --------------------------------------------------

def get_redis() -> redis.Redis:
    host = os.getenv("VP_3D_REDIS_HOST", "redis")
    port = int(os.getenv("VP_3D_REDIS_PORT", "6379"))

    return redis.Redis(
        host=host,
        port=port,
        decode_responses=True
    )


# --------------------------------------------------
# QUEUE KEYS
# --------------------------------------------------

def queue_key() -> str:
    return os.getenv("VP_3D_QUEUE_KEY", "vp:3d:jobs")


def processing_key() -> str:
    return queue_key() + ":processing"


# --------------------------------------------------
# ADD JOB TO QUEUE
# --------------------------------------------------

def enqueue_job(job_id: str):

    r = get_redis()

    r.lpush(queue_key(), job_id)


# --------------------------------------------------
# GET JOB SAFELY
# --------------------------------------------------

def blocking_pop(timeout_sec: int = 30):

    r = get_redis()

    job_id = r.brpoplpush(
        queue_key(),
        processing_key(),
        timeout=timeout_sec
    )

    if job_id is None:
        return None

    return (processing_key(), job_id)


# --------------------------------------------------
# MARK JOB DONE
# --------------------------------------------------

def mark_done(job_id: str):

    r = get_redis()

    r.lrem(
        processing_key(),
        1,
        job_id
    )


# --------------------------------------------------
# REQUEUE STALE JOBS
# --------------------------------------------------

def requeue_processing():

    r = get_redis()

    items = r.lrange(processing_key(), 0, -1)

    for job_id in items:

        r.lrem(processing_key(), 1, job_id)

        r.lpush(queue_key(), job_id)