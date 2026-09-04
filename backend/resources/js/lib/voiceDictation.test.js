import { describe, expect, it } from 'vitest';
import { appendTranscript, speechRecognitionConstructor, speechRecognitionErrorKey } from './voiceDictation';

describe('voice dictation helpers', () => {
    it('uses the standard or Chrome-prefixed recognition API', () => {
        const standard = function StandardRecognition() {};
        const chrome = function ChromeRecognition() {};

        expect(speechRecognitionConstructor({ SpeechRecognition: standard, webkitSpeechRecognition: chrome })).toBe(standard);
        expect(speechRecognitionConstructor({ webkitSpeechRecognition: chrome })).toBe(chrome);
        expect(speechRecognitionConstructor({})).toBeNull();
    });

    it('appends a normalized transcript without replacing existing notes', () => {
        expect(appendTranscript('Stan policzony.', ' Jedna sztuka znaleziona. ')).toBe('Stan policzony. Jedna sztuka znaleziona.');
        expect(appendTranscript('', '  Notatka głosowa  ')).toBe('Notatka głosowa');
        expect(appendTranscript('Bez zmian', '   ')).toBe('Bez zmian');
    });

    it('does not show an error after an intentional stop', () => {
        expect(speechRecognitionErrorKey('aborted')).toBeNull();
        expect(speechRecognitionErrorKey('not-allowed')).toBe('Chrome does not have permission to use the microphone.');
    });
});
