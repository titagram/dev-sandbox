declare const describe: any;
declare const expect: any;
declare const it: any;

import { resolveApiBaseUrl } from "./apiBaseUrl";

describe("resolveApiBaseUrl", () => {
  it("prefers the explicit Create React App API URL", () => {
    expect(
      resolveApiBaseUrl(
        {
          REACT_APP_API_BASE_URL: "https://api.example.test",
          VITE_API_BASE_URL: "https://legacy.example.test",
        },
        "https://browser.example.test",
      ),
    ).toBe("https://api.example.test");
  });

  it("falls back to the legacy Vite API URL", () => {
    expect(
      resolveApiBaseUrl(
        { VITE_API_BASE_URL: "https://legacy.example.test" },
        "https://browser.example.test",
      ),
    ).toBe("https://legacy.example.test");
  });

  it("uses the browser origin when configured URLs are empty", () => {
    expect(
      resolveApiBaseUrl(
        { REACT_APP_API_BASE_URL: "", VITE_API_BASE_URL: "" },
        "https://home-sweet-home.cloud",
      ),
    ).toBe("https://home-sweet-home.cloud");
  });

  it("uses localhost when neither configuration nor a browser origin exists", () => {
    expect(resolveApiBaseUrl({})).toBe("http://127.0.0.1:8000");
  });
});
