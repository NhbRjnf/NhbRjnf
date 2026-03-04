FROM ubuntu:22.04

ENV DEBIAN_FRONTEND=noninteractive
WORKDIR /app

# system deps
RUN apt-get update && apt-get install -y --no-install-recommends \
    ca-certificates curl wget unzip \
    python3 python3-pip python3-venv \
    freecad freecad-python3 \
    blender \
    nodejs npm \
    imagemagick \
    && rm -rf /var/lib/apt/lists/*

# gltf-transform
RUN npm i -g @gltf-transform/cli

COPY requirements.txt /app/requirements.txt
RUN pip3 install --no-cache-dir -r /app/requirements.txt

COPY worker.py /app/worker.py
COPY directus.py /app/directus.py
COPY vp_queue.py /app/vp_queue.py
COPY scripts /app/scripts

ENV PYTHONUNBUFFERED=1
CMD ["python3", "/app/worker.py"]
