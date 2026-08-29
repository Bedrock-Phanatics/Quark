# PHP is built in a separate image from Quark, so source changes do not rebuild the PHP toolchain.
FROM ubuntu:22.04 AS build-php
ARG COMPILE_SH_ARGS="-g -P5 -z 8.4"

RUN apt-get update && apt-get install --no-install-recommends -y curl ca-certificates build-essential m4 gzip bzip2 bison git cmake autoconf automake pkg-config libtool libtool-bin re2c

RUN mkdir /opt/quark
COPY --chmod=700 ./build/php/compile.sh /opt/quark
WORKDIR /opt/quark
RUN ./compile.sh -t linux64 -j ${THREADS:-$(grep -E ^processor /proc/cpuinfo | wc -l)} ${COMPILE_SH_ARGS}

FROM ubuntu:22.04 AS build-quark
ARG GIT_HASH=""
RUN test ! -z ${GIT_HASH} || (echo "Missing build-arg GIT_HASH" && false)

RUN apt-get update && apt-get install --no-install-recommends -y curl ca-certificates 7zip
RUN mkdir /opt/quark
COPY --from=build-php /opt/quark/bin /opt/quark/bin
RUN ln -s /opt/quark/bin/php7/bin/php /usr/bin/php
RUN curl -L https://getcomposer.org/installer | php
RUN mv composer.phar /usr/bin/composer

COPY ./build/server-phar.php ./build/server-phar-stub.php /opt/quark/repo/build/
COPY ./resources /opt/quark/repo/resources
COPY ./src /opt/quark/repo/src
COPY ./generated /opt/quark/repo/generated
COPY ./composer.json ./composer.lock /opt/quark/repo/

WORKDIR /opt/quark/repo
RUN composer install --classmap-authoritative --no-dev --prefer-dist
RUN php vendor/axolotl-pm/bedrock-data/.minify_json.php
RUN php -dphar.readonly=0 ./build/server-phar.php --git ${GIT_HASH}
# Just to make sure the build script didn't false-positive-exit
RUN test -f /opt/quark/repo/Quark.phar

FROM ubuntu:22.04
LABEL org.opencontainers.image.source="https://github.com/Bedrock-Phanatics/Quark"

RUN apt-get update && apt-get install --no-install-recommends -y ca-certificates wget

RUN groupadd -g 1000 quark
RUN useradd -r --no-create-home -u 1000 -g quark quark

WORKDIR /opt/quark
COPY --from=build-php /opt/quark/bin /opt/quark/bin
RUN ln -s /opt/quark/bin/php7/bin/php /usr/bin/php
COPY --from=build-quark /opt/quark/repo/Quark.phar Quark.phar
ADD ./docker/start.sh /usr/bin/start-quark

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
