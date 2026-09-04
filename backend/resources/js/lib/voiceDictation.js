export function speechRecognitionConstructor(browserWindow = globalThis.window) {
    return browserWindow?.SpeechRecognition ?? browserWindow?.webkitSpeechRecognition ?? null;
}

export function appendTranscript(value, transcript) {
    const current = String(value ?? '').trimEnd();
    const spoken = String(transcript ?? '').trim();

    if (!spoken) return current;
    return current ? `${current} ${spoken}` : spoken;
}

export function speechRecognitionErrorKey(error) {
    switch (error) {
        case 'aborted':
            return null;
        case 'not-allowed':
        case 'service-not-allowed':
            return 'Chrome does not have permission to use the microphone.';
        case 'audio-capture':
            return 'The microphone is unavailable.';
        case 'no-speech':
            return 'No speech was detected. Try again.';
        case 'network':
            return 'Voice recognition requires a network connection.';
        default:
            return 'Voice recognition could not be started.';
    }
}
