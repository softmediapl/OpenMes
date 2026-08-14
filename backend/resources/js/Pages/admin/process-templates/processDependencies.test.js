import { describe, expect, it } from 'vitest';
import { processDependencyPayload, validateProcessDependencies } from './processDependencies';

const steps = [{ id: 1 }, { id: 2 }, { id: 3 }];

describe('process dependencies', () => {
    it('accepts a directed acyclic graph with a merge', () => {
        expect(
            validateProcessDependencies(steps, [
                { predecessor_step_id: 1, successor_step_id: 3 },
                { predecessor_step_id: 2, successor_step_id: 3 },
            ]),
        ).toBeNull();
    });

    it('rejects self references, duplicates and cycles', () => {
        expect(
            validateProcessDependencies(steps, [
                { predecessor_step_id: 1, successor_step_id: 1 },
            ]),
        ).toBe('self');
        expect(
            validateProcessDependencies(steps, [
                { predecessor_step_id: 1, successor_step_id: 2 },
                { predecessor_step_id: 1, successor_step_id: 2 },
            ]),
        ).toBe('duplicate');
        expect(
            validateProcessDependencies(steps, [
                { predecessor_step_id: 1, successor_step_id: 2 },
                { predecessor_step_id: 2, successor_step_id: 1 },
            ]),
        ).toBe('cycle');
    });

    it('drops explicit edges in sequential mode', () => {
        expect(
            processDependencyPayload('sequential', [
                { predecessor_step_id: '1', successor_step_id: '2', lag_minutes: '5' },
            ]),
        ).toEqual({ dependency_mode: 'sequential', dependencies: [] });
    });
});
