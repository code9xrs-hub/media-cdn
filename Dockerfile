FROM php:8.2-cli-alpine

# Install cURL and OpenSSL (built into standard PHP, adding tzdata for timezone)
RUN apk add --no-cache curl openssl tzdata

# Set working directory
WORKDIR /app

# Copy application files
COPY . /app

# Set timezone
ENV TZ=UTC

# Run long-polling runner (24/7 continuous operation without webhooks)
CMD ["php", "poll.php"]
