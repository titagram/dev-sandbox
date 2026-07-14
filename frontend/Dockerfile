FROM node:22.12.0-alpine AS build

WORKDIR /app

COPY package.json yarn.lock ./
RUN yarn install --frozen-lockfile

COPY . .

ARG REACT_APP_USE_MOCK=false
ARG REACT_APP_API_BASE_URL=https://home-sweet-home.cloud

ENV REACT_APP_USE_MOCK=${REACT_APP_USE_MOCK}
ENV REACT_APP_API_BASE_URL=${REACT_APP_API_BASE_URL}

RUN yarn build

FROM nginx:1.27-alpine

COPY nginx.conf /etc/nginx/conf.d/default.conf
COPY --from=build /app/build /usr/share/nginx/html
