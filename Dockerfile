# Use your current image as the base
FROM devwithlando/php:8.1-apache-4

#== msmtp start ==

# Install msmtp and other necessary packages
RUN apt-get update && apt-get install -y \
    msmtp \
    mailutils \
    && rm -rf /var/lib/apt/lists/*

# Copy msmtp configuration file
COPY msmtprc /etc/msmtprc

# Set permissions for msmtprc
RUN chmod 644 /etc/msmtprc

# Create msmtp log file and set permissions
RUN touch /var/log/msmtp.log \
    && chmod 666 /var/log/msmtp.log

RUN echo "sendmail_path = /usr/bin/msmtp -t" > /usr/local/etc/php/conf.d/sendmail.ini

# == msmtp end ==

# Set home directory
ENV HOME /home/http
RUN mkdir -p $HOME && chown www-data:www-data $HOME

# Enable mod_rewrite
RUN a2enmod rewrite

# Copy your local apache-config.conf file into the container
COPY ./apache-config.conf /etc/apache2/sites-enabled/000-default.conf

# Copy the Drupal codebase into the container
COPY . /var/www/

# Set permissions 
RUN chown -R www-data:www-data /var/www

# Switch to 'www-data' user
USER www-data
