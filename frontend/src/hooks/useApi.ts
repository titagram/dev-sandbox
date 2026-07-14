import { useCallback, useEffect, useState } from "react";

export interface AsyncState<T> {
  data: T | null;
  loading: boolean;
  error: string | null;
  reload: () => void;
}

export function useApi<T>(fn: () => Promise<T>, deps: any[] = []): AsyncState<T> {
  const [data, setData] = useState<T | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [tick, setTick] = useState(0);

  // eslint-disable-next-line react-hooks/exhaustive-deps
  const run = useCallback(fn, deps);

  useEffect(() => {
    let active = true;
    setLoading(true);
    setError(null);
    run()
      .then((d) => active && setData(d))
      .catch((e) => active && setError(e?.message || "Something went wrong."))
      .finally(() => active && setLoading(false));
    return () => { active = false; };
  }, [run, tick]);

  return { data, loading, error, reload: () => setTick((t) => t + 1) };
}
