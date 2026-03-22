FROM python:3.12-slim

WORKDIR /app

COPY requirements.txt .
RUN pip install --no-cache-dir -r requirements.txt

COPY . .

RUN openssl req -new -x509 -nodes \
    -keyout /app/cert.key -out /app/cert.pem \
    -days 3650 -sha256 \
    -subj "/C=CH/O=FDH/CN=users.local"

EXPOSE 8443

CMD ["gunicorn", "app:app", \
     "--bind", "0.0.0.0:8443", \
     "--certfile", "/app/cert.pem", \
     "--keyfile", "/app/cert.key", \
     "--workers", "2", \
     "--timeout", "30"]
