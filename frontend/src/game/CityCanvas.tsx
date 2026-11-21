import { useEffect, useRef } from 'react';
import { DreamWorldController, type PreviewOverlayState } from '../game/DreamWorldController';
import type { BuildingItem, IslandItem } from '../types/game';

interface CityCanvasProps {
  phaseKey: string;
  islands: IslandItem[];
  buildings: BuildingItem[];
  selectedBuildingId: number | null;
  preview: PreviewOverlayState | null;
  onSelectBuilding: (buildingId: number | null) => void;
}

export const CityCanvas: React.FC<CityCanvasProps> = ({
  phaseKey,
  islands,
  buildings,
  selectedBuildingId,
  preview,
  onSelectBuilding,
}) => {
  const hostRef = useRef<HTMLDivElement | null>(null);
  const controllerRef = useRef<DreamWorldController | null>(null);

  useEffect(() => {
    if (!hostRef.current) {
      return;
    }

    const ctrl = new DreamWorldController(hostRef.current, {
      phaseKey,
      islands,
      buildings,
      selectedBuildingId,
      preview,
      onSelectBuilding,
    });
    controllerRef.current = ctrl;

    const ro = new ResizeObserver((entries) => {
      for (const entry of entries) {
        ctrl.resize(entry.contentRect.width, entry.contentRect.height);
      }
    });
    ro.observe(hostRef.current);

    return () => {
      ro.disconnect();
      ctrl.destroy();
      controllerRef.current = null;
    };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  useEffect(() => {
    controllerRef.current?.sync({
      phaseKey,
      islands,
      buildings,
      selectedBuildingId,
      preview,
      onSelectBuilding,
    });
  }, [phaseKey, islands, buildings, selectedBuildingId, preview, onSelectBuilding]);

  return <div ref={hostRef} className="h-full w-full" aria-label="Сцена города снов" />;
};
