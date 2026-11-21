import Phaser from 'phaser';
import type { BuildingItem, IslandItem } from '../types/game';

export interface PreviewOverlayState {
  active: boolean;
  x: number;
  y: number;
  valid: boolean;
}

export interface SceneSnapshot {
  phaseKey: string;
  islands: IslandItem[];
  buildings: BuildingItem[];
  selectedBuildingId: number | null;
  preview: PreviewOverlayState | null;
  onSelectBuilding: (buildingId: number | null) => void;
}

class DreamCityScene extends Phaser.Scene {
  private snapshot: SceneSnapshot = {
    phaseKey: 'twilight',
    islands: [],
    buildings: [],
    selectedBuildingId: null,
    preview: null,
    onSelectBuilding: () => {},
  };

  private worldContainer!: Phaser.GameObjects.Container;
  private isDragging = false;
  private dragStartX = 0;
  private dragStartY = 0;
  private camStartX = 0;
  private camStartY = 0;

  constructor() {
    super({ key: 'DreamCityScene' });
  }

  public updateSnapshot(next: SceneSnapshot): void {
    this.snapshot = next;
    if (this.sys.isActive()) {
      this.applyPhaseTint();
      this.renderCity();
    }
  }

  public create(): void {
    this.worldContainer = this.add.container(0, 0);
    this.applyPhaseTint();
    this.renderCity();

    this.input.on('pointerdown', (pointer: Phaser.Input.Pointer) => {
      this.isDragging = true;
      this.dragStartX = pointer.x;
      this.dragStartY = pointer.y;
      this.camStartX = this.worldContainer.x;
      this.camStartY = this.worldContainer.y;
    });

    this.input.on('pointermove', (pointer: Phaser.Input.Pointer) => {
      if (!this.isDragging) {
        return;
      }
      this.worldContainer.x = this.camStartX + (pointer.x - this.dragStartX);
      this.worldContainer.y = this.camStartY + (pointer.y - this.dragStartY);
    });

    this.input.on('pointerup', () => {
      this.isDragging = false;
    });

    this.input.on('wheel', (_pointer: Phaser.Input.Pointer, _dx: number, dy: number) => {
      const nextZoom = Phaser.Math.Clamp(this.cameras.main.zoom + (dy > 0 ? -0.1 : 0.1), 0.6, 1.8);
      this.cameras.main.setZoom(nextZoom);
    });
  }

  private applyPhaseTint(): void {
    const colorMap: Record<string, number> = {
      lucid_calm: 0x0f2942,
      twilight: 0x161833,
      collective_nightmare: 0x291124,
    };
    const bg = colorMap[this.snapshot.phaseKey] ?? 0x161833;
    this.cameras.main.setBackgroundColor(bg);
  }

  private renderCity(): void {
    this.worldContainer.removeAll(true);

    const islands =
      this.snapshot.islands.length > 0
        ? this.snapshot.islands
        : [{ id: 1, island_index: 0, is_unlocked: true }];

    const gridCols = 8;
    const gridRows = 8;
    const cellSize = 36;

    islands.forEach((island, idx) => {
      const baseX = 120 + idx * (gridCols * cellSize + 80);
      const baseY = 120;

      const gfx = this.add.graphics();
      gfx.fillStyle(island.is_unlocked ? 0x1e293b : 0x0f172a, 0.9);
      gfx.lineStyle(2, island.is_unlocked ? 0x6366f1 : 0x334155, 0.8);
      gfx.fillRoundedRect(baseX, baseY, gridCols * cellSize, gridRows * cellSize, 12);
      gfx.strokeRoundedRect(baseX, baseY, gridCols * cellSize, gridRows * cellSize, 12);

      for (let gx = 0; gx < gridCols; gx++) {
        for (let gy = 0; gy < gridRows; gy++) {
          gfx.lineStyle(1, 0x334155, 0.35);
          gfx.strokeRect(baseX + gx * cellSize, baseY + gy * cellSize, cellSize, cellSize);
        }
      }

      this.worldContainer.add(gfx);

      const label = this.add.text(
        baseX + 8,
        baseY - 24,
        `Остров #${island.island_index}${island.is_unlocked ? '' : ' (закрыт)'}`,
        { fontSize: '13px', color: '#cbd5e1' },
      );
      this.worldContainer.add(label);
    });

    if (this.snapshot.preview?.active) {
      const px = 120 + this.snapshot.preview.x * cellSize + 2;
      const py = 120 + this.snapshot.preview.y * cellSize + 2;
      const size = cellSize - 4;
      const strokeColor = this.snapshot.preview.valid ? 0x34d399 : 0xf43f5e;
      const fillColor = this.snapshot.preview.valid ? 0x10b981 : 0xe11d48;

      const ghost = this.add.rectangle(px + size / 2, py + size / 2, size, size, fillColor, 0.3);
      ghost.setStrokeStyle(2, strokeColor);
      this.worldContainer.add(ghost);
    }

    this.snapshot.buildings
      .filter((b) => b.status !== 'demolished')
      .forEach((b, index) => {
        const bx = b.position?.x ?? b.position_x ?? index;
        const by = b.position?.y ?? b.position_y ?? 0;
        const px = 120 + bx * cellSize + 4;
        const py = 120 + by * cellSize + 4;
        const size = cellSize - 8;
        const isSelected = this.snapshot.selectedBuildingId === b.id;

        const box = this.add.rectangle(
          px + size / 2,
          py + size / 2,
          size,
          size,
          isSelected ? 0xf59e0b : 0x38bdf8,
          0.9,
        );
        box.setStrokeStyle(2, isSelected ? 0xfef08a : 0xe0f2fe);
        box.setInteractive({ useHandCursor: true });
        box.on('pointerdown', (pointer: Phaser.Input.Pointer) => {
          pointer.event.stopPropagation();
          this.isDragging = false;
          this.snapshot.onSelectBuilding(b.id);
        });

        const tag = this.add.text(px + 4, py + 4, `L${b.level}`, {
          fontSize: '11px',
          color: '#0f172a',
        });

        this.worldContainer.add([box, tag]);
      });
  }
}

export class DreamWorldController {
  private game: Phaser.Game;
  private scene: DreamCityScene;

  constructor(container: HTMLElement, initialSnapshot: SceneSnapshot) {
    this.scene = new DreamCityScene();
    this.scene.updateSnapshot(initialSnapshot);

    this.game = new Phaser.Game({
      type: Phaser.AUTO,
      parent: container,
      width: Math.max(container.clientWidth, 320),
      height: Math.max(container.clientHeight, 320),
      backgroundColor: '#161833',
      scale: {
        mode: Phaser.Scale.RESIZE,
        autoCenter: Phaser.Scale.CENTER_BOTH,
      },
      scene: [this.scene],
    });
  }

  public sync(snapshot: SceneSnapshot): void {
    this.scene.updateSnapshot(snapshot);
  }

  public resize(width: number, height: number): void {
    if (this.game.scale) {
      this.game.scale.resize(Math.max(width, 320), Math.max(height, 320));
    }
  }

  public destroy(): void {
    this.game.destroy(true);
  }
}
