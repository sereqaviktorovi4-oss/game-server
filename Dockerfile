FROM php:8.1-apache

# Устанавливаем библиотеки для работы с PostgreSQL
RUN apt-get update && apt-get install -y libpq-dev \
    && docker-php-ext-install pgsql pdo_pgsql

# Включаем модуль перенаправления mod_rewrite
RUN a2enmod rewrite

# Копируем все файлы проекта в веб-директорию
COPY . /var/www/html/

# Открываем 80 порт
EXPOSE 80
