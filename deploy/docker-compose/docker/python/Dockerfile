# Builder stage
FROM python:slim AS builder

# Install system dependencies for Faiss and others
RUN apt-get update && apt-get install -y --no-install-recommends \
    build-essential \
    gcc \
    python3-dev \
    libopenblas-dev \
    libomp-dev \
    && rm -rf /var/lib/apt/lists/*

# Create install directory and store Python version
RUN mkdir -p /install/lib /install/nltk_data \
 && PY_VER=$(python -c "import sys; print(f'{sys.version_info.major}.{sys.version_info.minor}')" ) \
 && echo "PYTHON_VERSION=$PY_VER" > /install/python_env.sh

# Upgrade pip and install dependencies
RUN pip install --no-cache-dir --upgrade pip
RUN pip install --no-cache-dir --target=/install/lib \
    numpy \
    sentence-transformers \
    scikit-learn>=1.8.0 \
    tqdm \
    nltk \
    joblib \
    psutil \
    scipy \
    numba \
    orjson

# Download NLTK data
ENV PYTHONPATH=/install/lib
RUN python -m nltk.downloader -q -d /install/nltk_data \
    punkt \
    punkt_tab \
    averaged_perceptron_tagger \
    averaged_perceptron_tagger_eng \
    wordnet

# Set permissions for NLTK data
RUN chmod -R 755 /install/nltk_data

# Purge pip cache
RUN pip cache purge

# Runtime stage
FROM python:slim

# Set environment variables
ENV NLTK_DATA=/install/nltk_data
ENV PYTHONPATH=/install/lib

# Copy dependencies from builder
COPY --from=builder /install /install

# Verify Python version
RUN . /install/python_env.sh && echo "Using Python version $PYTHON_VERSION"

# Create directories for volume mounts
RUN mkdir -p /app/tools /app/storage/app/private/app /app/checkpoints /app/cache \
 && chmod -R 755 /app/checkpoints /app/cache

# Keep container running (script runs from volume)
CMD ["tail", "-f", "/dev/null"]