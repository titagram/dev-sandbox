import React from "react";
import { renderToStaticMarkup } from "react-dom/server";
import { SourceMetaInline } from "./Badges";
import { SourceMeta } from "@/types/devboard";

declare const test: any;
declare const expect: any;

const source = (type: string, origin: string): SourceMeta => ({
  type,
  status: "verified_from_code",
  origin,
  generated_at: "2026-07-14T00:00:00Z",
} as unknown as SourceMeta);

test("renders canonical graph source metadata without crashing", () => {
  const markup = renderToStaticMarkup(
    <SourceMetaInline source={source("canonical_graph", "canonical projection")} />,
  );

  expect(markup).toContain("Canonical Graph");
  expect(markup).toContain("canonical projection");
});

test("renders an unknown future source type without crashing", () => {
  const markup = renderToStaticMarkup(
    <SourceMetaInline source={source("future_source_type", "future source")} />,
  );

  expect(markup).toContain("Future Source Type");
  expect(markup).toContain("future source");
});
