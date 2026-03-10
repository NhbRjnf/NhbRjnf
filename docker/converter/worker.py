import os
import json
import shutil
import subprocess
import tempfile
import requests
from datetime import datetime, timezone

from directus import DirectusClient
from vp_queue import blocking_pop, mark_done

LOG_LEVEL = os.getenv("VP_3D_LOG_LEVEL", "INFO").upper()


def log(msg: str):
    print(f"[converter] {msg}", flush=True)


def now_iso():
    return datetime.now(timezone.utc).isoformat()


def run(cmd, timeout=1800):
    log("RUN: " + " ".join(cmd))

    p = subprocess.Popen(
        cmd,
        stdout=subprocess.PIPE,
        stderr=subprocess.STDOUT,
        text=True
    )

    try:
        out, _ = p.communicate(timeout=timeout)
    except subprocess.TimeoutExpired:
        p.kill()
        raise RuntimeError("Process timeout")

    if out:
        log(out[-4000:])

    if p.returncode != 0:
        raise RuntimeError(f"Command failed ({p.returncode}): {' '.join(cmd)}")


def wp_service_headers():
    token = os.getenv("VP_WP_SERVICE_TOKEN", "").strip()
    if not token:
        raise RuntimeError("VP_WP_SERVICE_TOKEN is empty")

    return {
        "Authorization": f"Bearer {token}"
    }


def download_source_file(source_url: str, out_path: str):
    log(f"Downloading source: {source_url}")

    with requests.get(
        source_url,
        headers=wp_service_headers(),
        stream=True,
        timeout=120
    ) as r:
        r.raise_for_status()

        with open(out_path, "wb") as f:
            for chunk in r.iter_content(chunk_size=1024 * 1024):
                if chunk:
                    f.write(chunk)


def wp_upload_file(file_path: str):
    wp_base = os.getenv("VP_WP_BASE_URL", "").rstrip("/")
    if not wp_base:
        raise RuntimeError("VP_WP_BASE_URL is empty")

    log(f"Uploading file to WordPress: {file_path}")

    with open(file_path, "rb") as fp:
        files = {
            "file": (os.path.basename(file_path), fp)
        }

        res = requests.post(
            f"{wp_base}/wp-json/vp/v1/upload",
            headers=wp_service_headers(),
            files=files,
            timeout=300,
        )

    res.raise_for_status()

    payload = res.json()

    if not payload.get("ok"):
        raise RuntimeError(f"WordPress upload failed: {json.dumps(payload)}")

    return payload


def gltf_optimize(glb_path: str):
    if os.getenv("VP_3D_GLTF_TRANSFORM_OPTIMIZE", "1") != "1":
        return

    log("Running glTF optimize")

    out = glb_path.replace(".glb", ".opt.glb")

    run([
        "gltf-transform",
        "optimize",
        glb_path,
        out
    ])

    shutil.move(out, glb_path)


def detect_extension(job):
    meta = job.get("meta") or {}
    filename = meta.get("filename", "")
    ext = os.path.splitext(filename)[1].lower()

    if not ext:
        ext = ".step"

    return ext


def main():
    dx = DirectusClient()

    log("Worker started. Waiting for jobs...")

    while True:
        item = blocking_pop(timeout_sec=30)

        if not item:
            continue

        _, job_id = item

        log(f"JOB: {job_id}")

        tmpdir = tempfile.mkdtemp(prefix="vp3d_")

        try:
            dx.patch_job(job_id, {
                "status": "processing",
                "progress": 1,
                "error": None,
                "updated_at": now_iso()
            })

            job = dx.get_job(job_id)

            source_file_url = (job.get("source_file_url") or "").strip()
            if not source_file_url:
              raise RuntimeError("Job has no source_file_url")

            ext = detect_extension(job)
            inp_path = os.path.join(tmpdir, "input" + ext)

            dx.patch_job(job_id, {
              "progress": 5,
              "updated_at": now_iso()
            })

            download_source_file(source_file_url, inp_path)

            size_mb = os.path.getsize(inp_path) / (1024 * 1024)
            max_mb = float(os.getenv("VP_3D_MAX_INPUT_MB", "200"))

            if size_mb > max_mb:
                raise RuntimeError(f"Input too large: {size_mb:.1f}MB > {max_mb}MB")

            ext = detect_extension(job)

            obj_path = os.path.join(tmpdir, "model.obj")
            glb_path = os.path.join(tmpdir, "model.glb")
            png_path = os.path.join(tmpdir, "preview.png")

            dx.patch_job(job_id, {
                "progress": 15,
                "updated_at": now_iso()
            })

            converted = False

            if ext in [".step", ".stp", ".iges", ".igs"]:
              step_path = os.path.join(tmpdir, "model.step")
              shutil.copy(inp_path, step_path)

              run([
                "freecadcmd",
                "/app/scripts/freecad_export_obj.py",
                step_path,
                obj_path
              ])

              converted = True

            dx.patch_job(job_id, {
                "progress": 35,
                "updated_at": now_iso()
            })

            if converted:
                run([
                    "blender",
                    "-b",
                    "-P",
                    "/app/scripts/blender_convert_obj_to_glb.py",
                    "--",
                    obj_path,
                    glb_path
                ])
            else:
                run([
                    "blender",
                    "-b",
                    "-P",
                    "/app/scripts/blender_convert_obj_to_glb.py",
                    "--",
                    inp_path,
                    glb_path
                ])

            
            
            if not os.path.exists(glb_path) or os.path.getsize(glb_path) == 0:
              raise RuntimeError("GLB was not created")

            dx.patch_job(job_id, {
              "progress": 65,
              "updated_at": now_iso()
            })

            run([
              "blender",
              "-b",
              "-P",
              "/app/scripts/blender_render_preview.py",
              "--",
              glb_path,
              png_path
            ])

            if not os.path.exists(png_path) or os.path.getsize(png_path) == 0:
              raise RuntimeError("Preview PNG was not created")

            dx.patch_job(job_id, {
              "progress": 80,
              "updated_at": now_iso()
            })

            gltf_optimize(glb_path)

            dx.patch_job(job_id, {
              "progress": 90,
              "updated_at": now_iso()
            })

            out_upload = wp_upload_file(glb_path)
            prev_upload = wp_upload_file(png_path)

            out_file_id = out_upload.get("attachment_id")
            prev_file_id = prev_upload.get("attachment_id")

            out_file_url = (
              out_upload.get("url")
              or out_upload.get("file_url")
              or out_upload.get("source_url")
            )
            prev_file_url = (
              prev_upload.get("url")
              or prev_upload.get("file_url")
              or prev_upload.get("source_url")
            )

            meta = {
              "source_file_url": source_file_url,
              "result_glb_wp_id": out_file_id,
              "preview_image_wp_id": prev_file_id,
              "result_glb_url": out_file_url,
              "preview_image_url": prev_file_url,
              "sizes": {
                "input_bytes": os.path.getsize(inp_path),
                "glb_bytes": os.path.getsize(glb_path),
                "png_bytes": os.path.getsize(png_path),
              },
              "finished_at": now_iso(),
            }

            patch_payload = {
              "status": "completed",
              "progress": 100,
              "meta": meta,
              "completed_at": now_iso(),
              "updated_at": now_iso(),
            }

            if out_file_id is not None:
              patch_payload["result_glb_wp_id"] = out_file_id

            if prev_file_id is not None:
              patch_payload["preview_image_wp_id"] = prev_file_id

            if out_file_url:
              patch_payload["result_glb_url"] = out_file_url

            if prev_file_url:
              patch_payload["preview_image_url"] = prev_file_url

            dx.patch_job(job_id, patch_payload)

            mark_done(job_id)
            log(f"DONE job={job_id}")

        except Exception as e:
            err = str(e)
            log(f"FAIL job={job_id}: {err}")

            try:
                dx.patch_job(job_id, {
                    "status": "failed",
                    "error": err,
                    "updated_at": now_iso()
                })
            except Exception as e2:
                log(f"Patch error failed: {e2}")

        finally:
            shutil.rmtree(tmpdir, ignore_errors=True)


if __name__ == "__main__":
    main()