FROM node:22.12.0-alpine@sha256:51eff88af6dff26f59316b6e356188ffa2c422bd3c3b76f2556a2e7e89d080bd AS build

WORKDIR /app

COPY package.json yarn.lock ./
RUN yarn install --frozen-lockfile

COPY . .

ARG REACT_APP_USE_MOCK=false
ARG REACT_APP_API_BASE_URL=

ENV REACT_APP_USE_MOCK=${REACT_APP_USE_MOCK}
ENV REACT_APP_API_BASE_URL=${REACT_APP_API_BASE_URL}

RUN yarn build

FROM nginx:1.27-alpine@sha256:65645c7bb6a0661892a8b03b89d0743208a18dd2f3f17a54ef4b76fb8e2f2a10

COPY nginx.conf /etc/nginx/conf.d/default.conf
COPY --from=build /app/build /usr/share/nginx/html
