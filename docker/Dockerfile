# PHP is built in a separate image from Quark, so source changes do not rebuild the PHP toolchain.
FROM ubuntu:22.04@sha256:5ec03bb3441e8b0bf3b4f9cd4629a1ae763010dc3035bb8da3ae6cf026486401 AS build-php
ARG COMPILE_SH_ARGS="-g -P5 -z 8.4"

RUN apt-get update && apt-get install --no-install-recommends -y curl ca-certificates build-essential m4 gzip bzip2 bison git cmake autoconf automake pkg-config libtool libtool-bin re2c && rm -rf /var/lib/apt/lists/*

RUN mkdir /opt/quark
COPY --chmod=700 ./build/php/compile.sh /opt/quark
WORKDIR /opt/quark
RUN ./compile.sh -t linux64 -j ${THREADS:-$(grep -E ^processor /proc/cpuinfo | wc -l)} ${COMPILE_SH_ARGS}

FROM ubuntu:22.04@sha256:5ec03bb3441e8b0bf3b4f9cd4629a1ae763010dc3035bb8da3ae6cf026486401 AS build-quark
ARG GIT_HASH=""
ARG BUILD_NUMBER="0"
RUN test -n "${GIT_HASH}" || (echo "Missing build-arg GIT_HASH" && false)

RUN apt-get update && apt-get install --no-install-recommends -y ca-certificates 7zip && rm -rf /var/lib/apt/lists/*
RUN mkdir /opt/quark
COPY --from=build-php /opt/quark/bin /opt/quark/bin
RUN ln -s /opt/quark/bin/php7/bin/php /usr/bin/php
COPY --from=composer:2.10.3@sha256:af98f42dfff7c68ba8d53c2164fd9fde1087b7d449514baa38c418b1f6bc4bac /usr/bin/composer /usr/bin/composer

COPY ./build/server-phar.php ./build/server-phar-stub.php /opt/quark/repo/build/
COPY ./resources /opt/quark/repo/resources
COPY ./src /opt/quark/repo/src
COPY ./generated /opt/quark/repo/generated
COPY ./composer.json ./composer.lock /opt/quark/repo/

WORKDIR /opt/quark/repo
RUN composer install --classmap-authoritative --no-dev --prefer-dist --no-interaction --no-progress
RUN php vendor/axolotl-pm/bedrock-data/.minify_json.php
RUN php -dphar.readonly=0 ./build/server-phar.php --git "${GIT_HASH}" --build "${BUILD_NUMBER}"
# Just to make sure the build script didn't false-positive-exit
RUN test -f /opt/quark/repo/Quark.phar

FROM ubuntu:22.04@sha256:5ec03bb3441e8b0bf3b4f9cd4629a1ae763010dc3035bb8da3ae6cf026486401
LABEL org.opencontainers.image.source="https://github.com/Bedrock-Phanatics/Quark"

RUN apt-get update && apt-get install --no-install-recommends -y ca-certificates && rm -rf /var/lib/apt/lists/*

RUN groupadd -g 1000 quark
RUN useradd -r --no-create-home -u 1000 -g quark quark

WORKDIR /opt/quark
COPY --from=build-php /opt/quark/bin /opt/quark/bin
RUN ln -s /opt/quark/bin/php7/bin/php /usr/bin/php
COPY --from=build-quark /opt/quark/repo/Quark.phar Quark.phar
COPY --chmod=755 ./docker/start.sh /usr/bin/start-quark

RUN mkdir /plugins /data
RUN chown 1000:1000 /plugins /data . -R
RUN chmod o+x /usr/bin/php

USER quark

ENV TERM=xterm

EXPOSE 19132/tcp
EXPOSE 19132/udp
EXPOSE 19133/udp

VOLUME ["/data", "/plugins"]

CMD ["start-quark"]
