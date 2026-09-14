FROM php:8.2-cli-alpine

# Install cURL and OpenSSL (built into standard PHP, adding tzdata for timezone)
RUN apk add --no-cache curl openssl tzdata

# Set working directory
WORKDIR /app

# Copy application files
COPY . /app

# Set timezone
ENV TZ=UTC

# Run web server for Render health check on $PORT while running long-polling runner in parallel
CMD sh -c "php -S 0.0.0.0:${PORT:-10000} & php poll.php"
