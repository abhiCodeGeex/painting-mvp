# Offline renderer for the 360° rooms (Blender Cycles, CPU + OpenImageDenoise).
# Used only to produce the room panoramas in public/3d/rooms — never part of the website.
FROM debian:bookworm-slim

ARG BLENDER_VERSION=4.2.23
RUN apt-get update && apt-get install -y --no-install-recommends \
        ca-certificates curl xz-utils libx11-6 libxi6 libxxf86vm1 libxfixes3 libxrender1 libxkbcommon0 \
        libgl1 libegl1 libsm6 libice6 libglib2.0-0 webp \
    && rm -rf /var/lib/apt/lists/* \
    && curl -fsSL "https://download.blender.org/release/Blender4.2/blender-${BLENDER_VERSION}-linux-x64.tar.xz" -o /tmp/blender.tar.xz \
    && mkdir -p /opt/blender && tar -xJf /tmp/blender.tar.xz -C /opt/blender --strip-components=1 \
    && rm /tmp/blender.tar.xz \
    && ln -s /opt/blender/blender /usr/local/bin/blender

WORKDIR /work
ENTRYPOINT ["blender", "--background", "--factory-startup"]
