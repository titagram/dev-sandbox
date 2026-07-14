import React, { act } from "react";
import { createRoot, Root } from "react-dom/client";
import AppErrorBoundary from "./AppErrorBoundary";

declare const test: any;
declare const expect: any;
declare const beforeEach: any;
declare const afterEach: any;
declare const jest: any;

let container: HTMLDivElement;
let root: Root;
let shouldThrow: boolean;
let consoleErrorSpy: any;

(globalThis as any).IS_REACT_ACT_ENVIRONMENT = true;

function ThrowsUntilReleased() {
  if (shouldThrow) {
    throw new Error("untrusted exception details should stay private");
  }

  return <p>Healthy dashboard</p>;
}

beforeEach(() => {
  container = document.createElement("div");
  document.body.appendChild(container);
  root = createRoot(container);
  shouldThrow = true;
  consoleErrorSpy = jest.spyOn(console, "error").mockImplementation(() => {});
});

afterEach(() => {
  act(() => root.unmount());
  container.remove();
  consoleErrorSpy.mockRestore();
});

test("shows the branded fallback and recovers after a boundary reset", () => {
  act(() => {
    root.render(
      <AppErrorBoundary>
        <ThrowsUntilReleased />
      </AppErrorBoundary>,
    );
  });

  const alert = container.querySelector('[role="alert"]');
  expect(alert).not.toBeNull();
  expect(alert?.textContent).toContain("Hades");
  expect(alert?.textContent).not.toContain("untrusted exception details");
  expect(alert?.textContent).not.toContain("Error:");
  expect(consoleErrorSpy).toHaveBeenCalledWith(
    "Hades dashboard render error",
    { componentStack: expect.any(String) },
  );

  const retryButton = Array.from(container.querySelectorAll("button")).find(
    (button) => button.textContent === "Try again",
  );
  expect(retryButton).not.toBeUndefined();

  act(() => {
    shouldThrow = false;
    retryButton?.click();
  });

  expect(container.textContent).toContain("Healthy dashboard");
  expect(container.querySelector('[role="alert"]')).toBeNull();
});
