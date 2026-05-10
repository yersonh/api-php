FROM php:8.2-cli-bullseye

# Instalar dependencias
RUN apt-get update && \
    apt-get install -y --no-install-recommends \
    unzip wget libaio1 && \
    apt-get clean && rm -rf /var/lib/apt/lists/*

# Descargar Oracle Instant Client
RUN mkdir -p /opt/oracle && \
    cd /opt/oracle && \
    wget -q https://download.oracle.com/otn_software/linux/instantclient/2110000/instantclient-basiclite-linux.x64-21.10.0.0.0dbru.zip -O ic-basic.zip && \
    wget -q https://download.oracle.com/otn_software/linux/instantclient/2110000/instantclient-sdk-linux.x64-21.10.0.0.0dbru.zip -O ic-sdk.zip && \
    unzip -q ic-basic.zip && \
    unzip -q ic-sdk.zip && \
    rm ic-basic.zip ic-sdk.zip && \
    echo /opt/oracle/instantclient_21_10 > /etc/ld.so.conf.d/oracle.conf && \
    ldconfig

# Variables Oracle
ENV LD_LIBRARY_PATH=/opt/oracle/instantclient_21_10
ENV ORACLE_HOME=/opt/oracle/instantclient_21_10

# Instalar OCI8
RUN export LDFLAGS="-Wl,-rpath,/opt/oracle/instantclient_21_10" && \
    echo 'instantclient,/opt/oracle/instantclient_21_10' | pecl install oci8-3.2.1 && \
    docker-php-ext-enable oci8

# Wallet
RUN mkdir -p /tmp/wallet

# App
WORKDIR /app
COPY . .

# EntryPoint
COPY entrypoint.sh /usr/local/bin/
RUN chmod +x /usr/local/bin/entrypoint.sh

EXPOSE 8080

CMD ["entrypoint.sh"]