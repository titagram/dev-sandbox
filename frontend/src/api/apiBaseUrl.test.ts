declare const describe: any;
declare const expect: any;
declare const it: any;

import { ApiBaseEnv, resolveApiBaseUrl } from "./apiBaseUrl";

type Equal<Left, Right> =
  (<Value>() => Value extends Left ? 1 : 2) extends
  (<Value>() => Value extends Right ? 1 : 2)
    ? true
    : false;
type Expect<Value extends true> = Value;
type OnlyCraApiEnv = Expect<
  Equal<keyof ApiBaseEnv, "REACT_APP_API_BASE_URL">
>;

const onlyCraApiEnv: OnlyCraApiEnv = true;

describe("resolveApiBaseUrl", () => {
  it("prefers the explicit Create React App API URL", () => {
    expect(onlyCraApiEnv).toBe(true);
    expect(
      resolveApiBaseUrl(
        { REACT_APP_API_BASE_URL: "https://api.example.test" },
        "https://browser.example.test",
      ),
    ).toBe("https://api.example.test");
  });

  it("uses the browser origin when configured URLs are empty", () => {
    expect(
      resolveApiBaseUrl(
        { REACT_APP_API_BASE_URL: "" },
        "https://home-sweet-home.cloud",
      ),
    ).toBe("https://home-sweet-home.cloud");
  });

  it("uses localhost when neither configuration nor a browser origin exists", () => {
    expect(resolveApiBaseUrl({})).toBe("http://127.0.0.1:8000");
  });
});
