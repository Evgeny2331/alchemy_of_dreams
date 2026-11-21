import { useEffect, useState } from 'react';
import { CityCanvas } from './game/CityCanvas';
import { useGameStore } from './store/useGameStore';

export function App() {
  const [devInitData, setDevInitData] = useState('');
  const [buildKey, setBuildKey] = useState('inkwell');
  const [coordX, setCoordX] = useState(0);
  const [coordY, setCoordY] = useState(0);
  const [moveX, setMoveX] = useState(1);
  const [moveY, setMoveY] = useState(0);
  const [expType, setExpType] = useState<'surface' | 'deep'>('surface');
  const [residentIdsRaw, setResidentIdsRaw] = useState('');
  const [compKeyA, setCompKeyA] = useState('fog');
  const [compQtyA, setCompQtyA] = useState('5.000000');
  const [drawer, setDrawer] = useState<'none' | 'expeditions' | 'alchemy'>('none');

  const {
    initialized,
    loading,
    mutating,
    error,
    lastErrorCode,
    lastRequestId,
    lastNotice,
    sessionToken,
    playerName,
    cityName,
    phaseKey,
    resources,
    islands,
    buildings,
    expeditions,
    archiveRecipes,
    selectedBuildingId,
    preview,
    bootstrap,
    syncNow,
    logout,
    selectBuilding,
    previewBuildAt,
    buildAt,
    upgradeSelectedBuilding,
    moveSelectedBuilding,
    demolishSelectedBuilding,
    loadExpeditions,
    startExpedition,
    claimExpedition,
    loadAlchemyArchive,
    runAlchemyExperiment,
  } = useGameStore();

  const activeIslandId = islands.find((i) => i.is_unlocked)?.id ?? islands[0]?.id ?? 1;

  useEffect(() => {
    void bootstrap();

    const onVisibility = () => {
      if (document.visibilityState === 'visible') {
        void useGameStore.getState().syncNow();
        void useGameStore.getState().loadExpeditions();
      }
    };
    document.addEventListener('visibilitychange', onVisibility);
    return () => document.removeEventListener('visibilitychange', onVisibility);
  }, [bootstrap]);

  const parseResidentIds = (): number[] =>
    residentIdsRaw
      .split(',')
      .map((s) => Number(s.trim()))
      .filter((n) => Number.isInteger(n) && n > 0);

  const previewOverlay = preview
    ? {
        active: true,
        x: coordX,
        y: coordY,
        valid: preview.position_valid !== false && preview.can_build,
      }
    : null;

  return (
    <div
      className="relative flex h-dvh w-screen flex-col bg-slate-950 text-slate-100"
      style={{
        paddingTop: 'var(--safe-top)',
        paddingBottom: 'var(--safe-bottom)',
      }}
    >
      <header className="z-10 flex flex-wrap items-center justify-between gap-2 border-b border-slate-800 bg-slate-900/90 px-3 py-2 backdrop-blur">
        <div className="flex items-center gap-3">
          <div>
            <div className="text-sm font-semibold text-indigo-300">{cityName}</div>
            <div className="text-xs text-slate-400">
              {playerName} · Фаза: <span className="font-mono text-amber-300">{phaseKey}</span>
            </div>
          </div>
        </div>

        <div className="flex flex-wrap items-center gap-2 text-xs font-mono">
          <span className="rounded bg-slate-800 px-2 py-1">Туман: {resources.fog}</span>
          <span className="rounded bg-slate-800 px-2 py-1">Тишина: {resources.silence}</span>
          <span className="rounded bg-slate-800 px-2 py-1">Воспоминания: {resources.memories}</span>
          <span className="rounded bg-slate-800 px-2 py-1">Метафоры: {resources.metaphors}</span>
          <span className="rounded bg-slate-800 px-2 py-1">Осколки: {resources.nightmare_shards}</span>
        </div>

        <div className="flex flex-wrap items-center gap-2">
          <button
            type="button"
            onClick={() => {
              const next = drawer === 'expeditions' ? 'none' : 'expeditions';
              setDrawer(next);
              if (next === 'expeditions') void loadExpeditions();
            }}
            disabled={!sessionToken}
            className="rounded border border-slate-700 bg-slate-800 px-2.5 py-1.5 text-xs text-slate-200 hover:bg-slate-700 disabled:opacity-40"
          >
            Экспедиции ({expeditions.length})
          </button>
          <button
            type="button"
            onClick={() => {
              const next = drawer === 'alchemy' ? 'none' : 'alchemy';
              setDrawer(next);
              if (next === 'alchemy') void loadAlchemyArchive();
            }}
            disabled={!sessionToken}
            className="rounded border border-slate-700 bg-slate-800 px-2.5 py-1.5 text-xs text-slate-200 hover:bg-slate-700 disabled:opacity-40"
          >
            Алхимия ({archiveRecipes.length})
          </button>
          <button
            type="button"
            onClick={() => void syncNow()}
            disabled={loading || mutating || !sessionToken}
            className="rounded bg-indigo-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-indigo-500 disabled:opacity-40"
          >
            {loading ? 'Синхронизация...' : 'Синхронизировать'}
          </button>
          {sessionToken && (
            <button
              type="button"
              onClick={() => void logout()}
              className="rounded border border-slate-700 px-2.5 py-1.5 text-xs text-slate-300 hover:bg-slate-800"
            >
              Выйти
            </button>
          )}
        </div>
      </header>

      <main className="relative flex-1 overflow-hidden">
        <CityCanvas
          phaseKey={phaseKey}
          islands={islands}
          buildings={buildings}
          selectedBuildingId={selectedBuildingId}
          preview={previewOverlay}
          onSelectBuilding={selectBuilding}
        />

        {lastNotice && !error && (
          <div className="absolute top-3 right-3 z-20 rounded-lg border border-emerald-500/40 bg-emerald-950/90 px-3 py-2 text-xs text-emerald-100 shadow">
            {lastNotice}
          </div>
        )}

        {drawer === 'expeditions' && sessionToken && (
          <section className="absolute top-3 left-3 z-20 w-80 max-w-[calc(100vw-1.5rem)] rounded-lg border border-slate-700 bg-slate-900/95 p-3 text-xs shadow-xl">
            <div className="mb-2 flex items-center justify-between font-semibold text-indigo-300">
              <span>Экспедиции</span>
              <button type="button" onClick={() => setDrawer('none')} className="text-slate-400 hover:text-white">
                ✕
              </button>
            </div>
            <div className="mb-2 flex gap-2">
              <select
                value={expType}
                onChange={(e) => setExpType(e.target.value as 'surface' | 'deep')}
                className="rounded border border-slate-700 bg-slate-950 px-2 py-1 text-xs"
              >
                <option value="surface">surface</option>
                <option value="deep">deep</option>
              </select>
              <input
                type="text"
                value={residentIdsRaw}
                onChange={(e) => setResidentIdsRaw(e.target.value)}
                placeholder="ID жителей: 1, 2"
                className="flex-1 rounded border border-slate-700 bg-slate-950 px-2 py-1 font-mono text-xs"
              />
              <button
                type="button"
                disabled={mutating}
                onClick={() => void startExpedition(expType, parseResidentIds())}
                className="rounded bg-indigo-600 px-2.5 py-1 font-medium text-white hover:bg-indigo-500 disabled:opacity-40"
              >
                Старт
              </button>
            </div>
            <div className="max-h-48 space-y-1.5 overflow-y-auto">
              {expeditions.length === 0 ? (
                <div className="text-slate-400">Экспедиций пока нет.</div>
              ) : (
                expeditions.map((exp) => (
                  <div
                    key={exp.id}
                    className="flex items-center justify-between rounded border border-slate-800 bg-slate-950/80 px-2 py-1.5"
                  >
                    <div>
                      <div className="font-mono text-slate-200">
                        #{exp.id} · {exp.type}
                      </div>
                      <div className="text-[11px] text-slate-400">{exp.status}</div>
                    </div>
                    {exp.status !== 'claimed' && (
                      <button
                        type="button"
                        disabled={mutating}
                        onClick={() => void claimExpedition(exp.id)}
                        className="rounded bg-emerald-600 px-2 py-1 text-[11px] font-medium text-white hover:bg-emerald-500 disabled:opacity-40"
                      >
                        Награда
                      </button>
                    )}
                  </div>
                ))
              )}
            </div>
          </section>
        )}

        {drawer === 'alchemy' && sessionToken && (
          <section className="absolute top-3 left-3 z-20 w-80 max-w-[calc(100vw-1.5rem)] rounded-lg border border-slate-700 bg-slate-900/95 p-3 text-xs shadow-xl">
            <div className="mb-2 flex items-center justify-between font-semibold text-amber-300">
              <span>Алхимия и Архив</span>
              <button type="button" onClick={() => setDrawer('none')} className="text-slate-400 hover:text-white">
                ✕
              </button>
            </div>
            <div className="mb-2 flex gap-2">
              <input
                type="text"
                value={compKeyA}
                onChange={(e) => setCompKeyA(e.target.value)}
                placeholder="key компонента"
                className="flex-1 rounded border border-slate-700 bg-slate-950 px-2 py-1 font-mono text-xs"
              />
              <input
                type="text"
                value={compQtyA}
                onChange={(e) => setCompQtyA(e.target.value)}
                className="w-20 rounded border border-slate-700 bg-slate-950 px-2 py-1 font-mono text-xs"
              />
              <button
                type="button"
                disabled={mutating}
                onClick={() =>
                  void runAlchemyExperiment([
                    { type: 'resource', key: compKeyA.trim(), quantity: compQtyA.trim() },
                  ])
                }
                className="rounded bg-amber-600 px-2.5 py-1 font-medium text-white hover:bg-amber-500 disabled:opacity-40"
              >
                Опыт
              </button>
            </div>
            <div className="mb-1 text-[11px] text-slate-400">Открытые рецепты:</div>
            <div className="max-h-40 space-y-1 overflow-y-auto">
              {archiveRecipes.length === 0 ? (
                <div className="text-slate-500">Архив пуст.</div>
              ) : (
                archiveRecipes.map((r) => (
                  <div
                    key={r.key}
                    className="flex items-center justify-between rounded border border-slate-800 bg-slate-950/80 px-2 py-1 font-mono text-[11px]"
                  >
                    <span>{r.key}</span>
                    <span className="text-amber-300">{r.category}</span>
                  </div>
                ))
              )}
            </div>
          </section>
        )}

        {sessionToken && (
          <div className="absolute bottom-3 left-3 z-20 flex max-w-md flex-col gap-1.5 rounded-lg border border-slate-800 bg-slate-900/95 px-3 py-2 text-xs shadow-lg">
            <div className="flex flex-wrap items-center gap-2">
              <input
                type="text"
                value={buildKey}
                onChange={(e) => setBuildKey(e.target.value)}
                className="w-28 rounded border border-slate-700 bg-slate-950 px-2 py-1 font-mono text-xs"
                aria-label="Ключ здания"
              />
              <input
                type="number"
                value={coordX}
                onChange={(e) => setCoordX(Number(e.target.value))}
                className="w-14 rounded border border-slate-700 bg-slate-950 px-2 py-1 font-mono text-xs"
                aria-label="Координата X"
              />
              <input
                type="number"
                value={coordY}
                onChange={(e) => setCoordY(Number(e.target.value))}
                className="w-14 rounded border border-slate-700 bg-slate-950 px-2 py-1 font-mono text-xs"
                aria-label="Координата Y"
              />
              <button
                type="button"
                disabled={mutating}
                onClick={() => void previewBuildAt(buildKey.trim(), activeIslandId, coordX, coordY)}
                className="rounded border border-slate-600 px-2.5 py-1 text-xs text-slate-200 hover:bg-slate-800 disabled:opacity-40"
              >
                Расчёт
              </button>
              <button
                type="button"
                disabled={mutating}
                onClick={() => void buildAt(buildKey.trim(), activeIslandId, coordX, coordY)}
                className="rounded bg-emerald-600 px-3 py-1 font-medium text-white hover:bg-emerald-500 disabled:opacity-40"
              >
                {mutating ? 'Отправка...' : 'Построить'}
              </button>
            </div>

            {preview && (
              <div className="rounded bg-slate-950/90 px-2 py-1 font-mono text-[11px] text-slate-300">
                Позиция: {preview.position_valid === false ? 'недоступна' : 'ок'} · Доступно:{' '}
                {preview.can_build ? 'да' : 'нет'} · Стоимость:{' '}
                {Object.entries(preview.cost)
                  .filter(([, v]) => Number(v) > 0)
                  .map(([k, v]) => `${k}: ${v}`)
                  .join(', ') || '0.000000'}
              </div>
            )}
          </div>
        )}

        {error && (
          <div className="absolute top-3 left-3 right-3 z-20 mx-auto max-w-xl rounded-lg border border-rose-500/40 bg-rose-950/90 px-4 py-3 text-xs text-rose-100 shadow-lg">
            <div className="font-semibold">
              {lastErrorCode ? `[${lastErrorCode}] ` : ''}
              {error}
              {lastRequestId ? ` (req: ${lastRequestId})` : ''}
            </div>
            {!sessionToken && (
              <form
                className="mt-2 flex gap-2"
                onSubmit={(e) => {
                  e.preventDefault();
                  void bootstrap(devInitData);
                }}
              >
                <input
                  type="text"
                  value={devInitData}
                  onChange={(e) => setDevInitData(e.target.value)}
                  placeholder="Вставьте Telegram initData для теста..."
                  className="flex-1 rounded border border-rose-700/60 bg-slate-950/80 px-2.5 py-1 text-xs text-slate-100"
                />
                <button
                  type="submit"
                  className="rounded bg-rose-600 px-3 py-1 text-xs font-medium text-white hover:bg-rose-500"
                >
                  Войти
                </button>
              </form>
            )}
          </div>
        )}

        {selectedBuildingId !== null && (
          <aside className="absolute bottom-3 right-3 z-20 flex flex-wrap items-center gap-2 rounded-lg border border-slate-700 bg-slate-900/95 px-4 py-2.5 text-xs shadow-xl">
            <div className="mr-1">
              Здание <span className="font-mono text-amber-300">#{selectedBuildingId}</span>
            </div>
            <input
              type="number"
              value={moveX}
              onChange={(e) => setMoveX(Number(e.target.value))}
              className="w-12 rounded border border-slate-700 bg-slate-950 px-1.5 py-1 font-mono text-xs"
              aria-label="Новая X"
            />
            <input
              type="number"
              value={moveY}
              onChange={(e) => setMoveY(Number(e.target.value))}
              className="w-12 rounded border border-slate-700 bg-slate-950 px-1.5 py-1 font-mono text-xs"
              aria-label="Новая Y"
            />
            <button
              type="button"
              disabled={mutating}
              onClick={() => void moveSelectedBuilding(activeIslandId, moveX, moveY)}
              className="rounded bg-sky-600 px-2.5 py-1 font-medium text-white hover:bg-sky-500 disabled:opacity-40"
            >
              Переместить
            </button>
            <button
              type="button"
              disabled={mutating}
              onClick={() => void upgradeSelectedBuilding()}
              className="rounded bg-amber-600 px-2.5 py-1 font-medium text-white hover:bg-amber-500 disabled:opacity-40"
            >
              Улучшить
            </button>
            <button
              type="button"
              disabled={mutating}
              onClick={() => void demolishSelectedBuilding()}
              className="rounded bg-rose-700 px-2.5 py-1 font-medium text-white hover:bg-rose-600 disabled:opacity-40"
            >
              Снести
            </button>
            <button
              type="button"
              onClick={() => selectBuilding(null)}
              className="rounded bg-slate-800 px-2.5 py-1 text-slate-300 hover:bg-slate-700"
            >
              Снять
            </button>
          </aside>
        )}

        {!initialized && loading && (
          <div className="pointer-events-none absolute inset-0 flex items-center justify-center bg-slate-950/60 text-sm text-slate-300">
            Загрузка мира снов...
          </div>
        )}
      </main>
    </div>
  );
}

export default App;
