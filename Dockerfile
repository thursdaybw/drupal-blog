# Use your current image as the base
FROM devwithlando/php:8.1-apache-4

# Install msmtp and other necessary packages
RUN apt-get update && apt-get install -y \
    sshfs \
    msmtp \
    mailutils \
    python3-venv \
    python3-pip && \
    rm -rf /var/lib/apt/lists/*

RUN pip install --no-cache-dir --upgrade "vastai>=0.3.1"
RUN echo 'export PATH="$HOME/.local/bin:$PATH"' >> /home/${username}/.bashrc

# Enable `user_allow_other` in `/etc/fuse.conf`
RUN echo "user_allow_other" > /etc/fuse.conf && chmod 644 /etc/fuse.conf

# Ensure correct permissions for /home/http/.cache
RUN mkdir -p /home/http/.cache && \
    chown -R www-data:www-data /home/http/.cache

# Copy msmtp configuration file
COPY msmtprc /etc/msmtprc

# Set permissions for msmtprc
RUN chmod 644 /etc/msmtprc

# Create msmtp log file and set permissions
RUN touch /var/log/msmtp.log \
    && chmod 666 /var/log/msmtp.log

RUN echo "sendmail_path = /usr/bin/msmtp -t" > /usr/local/etc/php/conf.d/sendmail.ini

# msmtp end

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

# Copy fonts to the container
COPY ./fonts /usr/share/fonts/custom

# Refresh font cache to make the fonts available
RUN fc-cache -f -v

COPY php-uploads.ini /usr/local/etc/php/conf.d/uploads.ini

# Set up SSH directory for www-data
RUN mkdir -p /home/http/.ssh && \
    chown www-data:www-data /home/http/.ssh && \
    chmod 700 /home/http/.ssh


# Create SSHFS mount point
RUN mkdir -p /var/tmp/sftp_mount && \
    chown www-data:www-data /var/tmp/sftp_mount && \
    chmod 700 /var/tmp/sftp_mount

COPY entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh

# Add NodeSource repo for modern Node.js
RUN curl -fsSL https://deb.nodesource.com/setup_18.x | bash - && \
    apt-get install -y nodejs && \
    node -v && npm -v

USER root
ENTRYPOINT ["/entrypoint.sh"]
