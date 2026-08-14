export function validateProcessDependencies(steps, dependencies) {
    const stepIds = new Set(steps.map((step) => Number(step.id)));
    const adjacency = new Map(steps.map((step) => [Number(step.id), []]));
    const indegree = new Map(steps.map((step) => [Number(step.id), 0]));
    const seen = new Set();

    for (const dependency of dependencies) {
        const predecessor = Number(dependency.predecessor_step_id);
        const successor = Number(dependency.successor_step_id);

        if (!stepIds.has(predecessor) || !stepIds.has(successor)) {
            return 'incomplete';
        }
        if (predecessor === successor) {
            return 'self';
        }

        const key = `${predecessor}:${successor}`;
        if (seen.has(key)) {
            return 'duplicate';
        }

        seen.add(key);
        adjacency.get(predecessor).push(successor);
        indegree.set(successor, indegree.get(successor) + 1);
    }

    const queue = [...indegree.entries()]
        .filter(([, degree]) => degree === 0)
        .map(([stepId]) => stepId);
    let visited = 0;

    while (queue.length > 0) {
        const stepId = queue.shift();
        visited += 1;
        for (const successor of adjacency.get(stepId)) {
            const nextDegree = indegree.get(successor) - 1;
            indegree.set(successor, nextDegree);
            if (nextDegree === 0) queue.push(successor);
        }
    }

    return visited === steps.length ? null : 'cycle';
}

export function processDependencyPayload(mode, dependencies) {
    return {
        dependency_mode: mode,
        dependencies:
            mode === 'explicit'
                ? dependencies.map((dependency) => ({
                      predecessor_step_id: Number(dependency.predecessor_step_id),
                      successor_step_id: Number(dependency.successor_step_id),
                      lag_minutes: Number(dependency.lag_minutes || 0),
                  }))
                : [],
    };
}
