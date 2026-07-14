export interface ApiBaseEnv {
  REACT_APP_API_BASE_URL?: string;
}

export function resolveApiBaseUrl(
  env: ApiBaseEnv,
  browserOrigin?: string,
): string {
  return (
    env.REACT_APP_API_BASE_URL ||
    browserOrigin ||
    "http://127.0.0.1:8000"
  );
}

const browserOrigin =
  typeof window === "undefined" ? undefined : window.location.origin;

export const API_BASE_URL = resolveApiBaseUrl(
  {
    REACT_APP_API_BASE_URL: process.env.REACT_APP_API_BASE_URL,
  },
  browserOrigin,
);
