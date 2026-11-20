FROM golang:1.27.4-alpine@sha256:70bd7ddea8b6cbe5d6943cde279d6b67feb10cc924fb9100d1d8757c1575b280 AS builder
WORKDIR /src
ADD --checksum=sha256:7b8de20699ec38a43c97d70dd5347ed8e19d3db96d0c9d76ded020f018cb6e5e https://codeload.github.com/caddyserver/caddy/tar.gz/0d98caea58f794f88e709b1bef2880996a471bca /tmp/source.tar.gz
RUN tar -xzf /tmp/source.tar.gz --strip-components=1 -C /src && rm /tmp/source.tar.gz
ENV GOMAXPROCS=2 CGO_ENABLED=0
RUN go get golang.org/x/net@v0.62.0 && go mod verify
RUN go test -p 2 ./caddyconfig/httpcaddyfile/...
RUN go build -p 2 -trimpath -ldflags="-s -w -X github.com/caddyserver/caddy/v2.CustomVersion=v2.11.9-security20261120" -o /caddy ./cmd/caddy && /caddy version

FROM caddy:2.11.9-alpine@sha256:003a97808e781aa9f7e75cc2f43d34d461099031faf5e20faefb9f61126836f0
COPY --from=builder /caddy /usr/bin/caddy

COPY deploy/portainer/Caddyfile /etc/caddy/Caddyfile
