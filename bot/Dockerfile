FROM python:3.12-slim
WORKDIR /app
ENV PYTHONDONTWRITEBYTECODE=1 PYTHONUNBUFFERED=1
RUN apt-get update && apt-get install -y --no-install-recommends fonts-dejavu-core \
    && rm -rf /var/lib/apt/lists/*
COPY requirements.txt .
RUN pip install --no-cache-dir -r requirements.txt
COPY config.py api_client.py card_renderer.py ui.py main.py misc.py ./
COPY api ./api
COPY handlers ./handlers
COPY keyboards ./keyboards
COPY services ./services
COPY states ./states
COPY texts ./texts
COPY utils ./utils
COPY assets ./assets
CMD ["python", "main.py"]
