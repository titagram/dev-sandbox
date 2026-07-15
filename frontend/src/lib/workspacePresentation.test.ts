declare const describe: any;
declare const expect: any;
declare const it: any;

import { workspacePresentation } from "./workspacePresentation";

describe("workspace presentation", () => {
  it("labels canonical Hades bindings instead of showing an unknown legacy clone", () => {
    expect(workspacePresentation("unknown", true)).toEqual({
      label: "Hades workspace linked",
      showLegacyDetails: false,
      tone: "green",
    });
  });

  it("retains local clone details when a local workspace is actually linked", () => {
    expect(workspacePresentation("linked", true).showLegacyDetails).toBe(true);
  });
});
