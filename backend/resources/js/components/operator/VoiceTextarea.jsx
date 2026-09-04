import { Mic, Square } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { __ } from '../../lib/i18n';
import { appendTranscript, speechRecognitionConstructor, speechRecognitionErrorKey } from '../../lib/voiceDictation';

export default function VoiceTextarea({ value, onChange, className = '', panelMode = false, ...textareaProps }) {
    const recognitionRef = useRef(null);
    const valueRef = useRef(value);
    const [listening, setListening] = useState(false);
    const [error, setError] = useState('');
    const supported = speechRecognitionConstructor() !== null;

    useEffect(() => {
        valueRef.current = value;
    }, [value]);

    useEffect(() => () => {
        recognitionRef.current?.abort();
        recognitionRef.current = null;
    }, []);

    const stop = () => {
        recognitionRef.current?.stop();
    };

    const start = () => {
        const Recognition = speechRecognitionConstructor();
        if (!Recognition) return;

        setError('');
        const recognition = new Recognition();
        recognition.lang = 'pl-PL';
        recognition.continuous = true;
        recognition.interimResults = false;
        recognition.maxAlternatives = 1;
        recognition.onstart = () => setListening(true);
        recognition.onresult = (event) => {
            const transcript = Array.from(event.results)
                .slice(event.resultIndex)
                .filter((result) => result.isFinal)
                .map((result) => result[0]?.transcript ?? '')
                .join(' ');
            const next = appendTranscript(valueRef.current, transcript);
            valueRef.current = next;
            onChange(next);
        };
        recognition.onerror = (event) => {
            const key = speechRecognitionErrorKey(event.error);
            if (key) setError(__(key));
        };
        recognition.onend = () => {
            recognitionRef.current = null;
            setListening(false);
        };
        recognitionRef.current = recognition;

        try {
            recognition.start();
        } catch {
            recognitionRef.current = null;
            setListening(false);
            setError(__('Voice recognition could not be started.'));
        }
    };

    const toggle = () => listening ? stop() : start();
    const buttonLabel = listening ? __('Stop voice dictation') : __('Add note by voice');

    return (
        <div>
            <div className="grid grid-cols-[minmax(0,1fr)_3rem] items-stretch gap-2">
                <textarea
                    {...textareaProps}
                    value={value}
                    onChange={(event) => onChange(event.target.value)}
                    className={className}
                />
                <button
                    type="button"
                    onClick={toggle}
                    disabled={!supported}
                    aria-label={supported ? buttonLabel : __('Voice dictation is not supported in this browser.')}
                    aria-pressed={listening}
                    title={supported ? buttonLabel : __('Voice dictation is not supported in this browser.')}
                    className={`flex min-h-12 min-w-12 items-center justify-center rounded-om-sm border text-om-ink disabled:cursor-not-allowed disabled:opacity-40 ${listening ? 'border-om-blocked bg-om-blocked-bg text-om-blocked' : 'border-om-line bg-om-card hover:bg-om-panel'} ${panelMode ? 'h-full' : ''}`}
                >
                    {listening ? <Square size={20} fill="currentColor" /> : <Mic size={22} />}
                </button>
            </div>
            {listening && <p className="mt-1 text-xs font-semibold text-om-running" role="status">{__('Listening… Tap again to stop.')}</p>}
            {error && <p className="mt-1 text-xs text-om-blocked" role="alert">{error}</p>}
        </div>
    );
}
