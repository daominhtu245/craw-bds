# One image for every collector; each compose service picks its command.
FROM python:3.12-slim

ENV PYTHONDONTWRITEBYTECODE=1 \
    PYTHONUNBUFFERED=1 \
    PIP_NO_CACHE_DIR=1

WORKDIR /srv/collectors

COPY pyproject.toml ./
RUN pip install "pydantic>=2.9,<3" "httpx>=0.28,<1" "telethon>=1.45,<2" "apify-client>=3.4,<4" "selectolax>=1.0,<2" "pytest>=9,<10"

COPY . .

RUN useradd --create-home --uid 1000 collector \
    && mkdir -p /data/spool \
    && chown -R collector:collector /data /srv/collectors
USER collector

CMD ["python", "-m", "pytest", "-q"]
