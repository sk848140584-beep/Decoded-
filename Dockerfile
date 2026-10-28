FROM php:8.2-cli

RUN apt-get update && apt-get install -y \
    libcurl4-openssl-dev \
    && docker-php-ext-install pdo pdo_mysql curl

WORKDIR /app
COPY . /app

# यह लाइन तुम्हारी index.php में ऑटोमैटिक Admin बनाने का कोड खुद जोड़ देगी
RUN sed -i 's/migrate_add_columns(\$pdo, \$driver);/migrate_add_columns(\$pdo, \$driver); \$au = env("ADMIN_USER", "admin"); \$ap = env("ADMIN_PASS", "Admin@12345"); if (\$driver === "mysql" \&\& (int)\$pdo->query("SELECT COUNT(*) FROM admins")->fetchColumn() === 0) { \$pdo->prepare("INSERT INTO admins (username, password_hash) VALUES (?, ?)")->execute([\$au, password_hash(\$ap, PASSWORD_DEFAULT)]); }/' /app/index.php

RUN mkdir -p /app/branding && chmod -R 777 /app/branding

CMD sh -c "php -S 0.0.0.0:${PORT:-8080} index.php"
