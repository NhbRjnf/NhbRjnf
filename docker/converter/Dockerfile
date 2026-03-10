FROM debian:bookworm-slim

ENV DEBIAN_FRONTEND=noninteractive

WORKDIR /app

RUN apt-get update && apt-get install -y \
    python3 \
    python3-pip \
    python3-venv \
    python3-numpy \
    python3-requests \
    blender \
    freecad \
    nodejs \
    npm \
    curl \
    ca-certificates \
    git \
    build-essential \
    libgl1 \
    libglib2.0-0 \
    libsm6 \
    libxrender1 \
    libxext6 \
    && rm -rf /var/lib/apt/lists/*

RUN npm install -g @gltf-transform/cli

COPY requirements.txt .
RUN pip3 install --break-system-packages --no-cache-dir -r requirements.txt

COPY worker.py .
COPY directus.py .
COPY vp_queue.py .
COPY scripts ./scripts

ENV VP_3D_REDIS_HOST=redis
ENV VP_3D_REDIS_PORT=6379
ENV VP_3D_QUEUE_KEY=vp:3d:jobs

CMD ["python3", "-u", "worker.py"]