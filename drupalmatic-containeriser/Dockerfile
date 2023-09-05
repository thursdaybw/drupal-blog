# Use your current image as the base
FROM devwithlando/php:8.1-apache-4 

# Don't use /var/www as the home directory because we end up with files like .bash_history
# in there, and on the host.
ENV HOME /home/http
RUN mkdir -p $HOME && chown ${HTTP_UID}:${HTTP_GID} $HOME

# Enable mod_rewrite
RUN a2enmod rewrite

# Copy your local apache-config.conf file into the container
COPY ./apache-config.conf /etc/apache2/sites-enabled/000-default.conf

