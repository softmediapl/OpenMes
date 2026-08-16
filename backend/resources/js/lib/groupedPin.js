export function pinDigits(value, length) {
    return String(value ?? '').replace(/\D/g, '').slice(0, length);
}

export function splitGroupedPin(value, length, groupSize) {
    const digits = pinDigits(value, length);
    const groups = Math.ceil(length / groupSize);

    return Array.from(
        { length: groups },
        (_, index) => digits.slice(index * groupSize, (index + 1) * groupSize),
    );
}

export function replacePinGroup(value, index, input, length, groupSize) {
    const digits = pinDigits(input, length);
    const parts = splitGroupedPin(value, length, groupSize);

    if (digits.length > groupSize) {
        let remaining = digits;

        for (let cursor = index; cursor < parts.length && remaining; cursor += 1) {
            const size = Math.min(groupSize, length - cursor * groupSize);
            parts[cursor] = remaining.slice(0, size);
            remaining = remaining.slice(size);
        }
    } else {
        parts[index] = digits;
    }

    return pinDigits(parts.join(''), length);
}
