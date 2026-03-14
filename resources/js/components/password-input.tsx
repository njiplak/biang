'use client';

import { AlertCircle, Check, Eye, EyeOff, X } from 'lucide-react';
import * as React from 'react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { cn } from '@/lib/utils';

export interface PasswordInputProps extends React.InputHTMLAttributes<HTMLInputElement> {
    showStrengthIndicator?: boolean;
    label?: string;
    placeholder?: string;
    strengthRules?: {
        minLength?: number;
        requireUppercase?: boolean;
        requireLowercase?: boolean;
        requireNumbers?: boolean;
        requireSpecialChars?: boolean;
    };
    error?: string;
}

interface PasswordStrength {
    score: number;
    feedback: string[];
    isValid: boolean;
}

const defaultStrengthRules = {
    minLength: 8,
    requireUppercase: true,
    requireLowercase: true,
    requireNumbers: true,
    requireSpecialChars: true,
};

const PasswordInput = React.forwardRef<HTMLInputElement, PasswordInputProps>(
    (
        {
            className,
            showStrengthIndicator = false,
            strengthRules = defaultStrengthRules,
            value,
            onChange,
            disabled,
            label,
            placeholder,
            error,
            ...props
        },
        ref,
    ) => {
        const [showPassword, setShowPassword] = React.useState(false);
        const [passwordStrength, setPasswordStrength] = React.useState<PasswordStrength>({
            score: 0,
            feedback: [],
            isValid: false,
        });

        const togglePasswordVisibility = React.useCallback(() => {
            setShowPassword((prev) => !prev);
        }, []);

        const handleKeyDown = React.useCallback(
            (e: React.KeyboardEvent) => {
                // Allow Enter or Space to toggle password visibility when focused on toggle button
                if (e.target === e.currentTarget && (e.key === 'Enter' || e.key === ' ')) {
                    e.preventDefault();
                    togglePasswordVisibility();
                }
            },
            [togglePasswordVisibility],
        );

        const calculatePasswordStrength = React.useCallback(
            (password: string): PasswordStrength => {
                const rules = { ...defaultStrengthRules, ...strengthRules };
                const feedback: string[] = [];
                let score = 0;

                if (!password) {
                    return { score: 0, feedback: ['Password is required'], isValid: false };
                }

                // Check minimum length
                if (password.length >= rules.minLength) {
                    score += 20;
                } else {
                    feedback.push(`At least ${rules.minLength} characters`);
                }

                // Check for uppercase letters
                if (rules.requireUppercase) {
                    if (/[A-Z]/.test(password)) {
                        score += 20;
                    } else {
                        feedback.push('One uppercase letter');
                    }
                }

                // Check for lowercase letters
                if (rules.requireLowercase) {
                    if (/[a-z]/.test(password)) {
                        score += 20;
                    } else {
                        feedback.push('One lowercase letter');
                    }
                }

                // Check for numbers
                if (rules.requireNumbers) {
                    if (/\d/.test(password)) {
                        score += 20;
                    } else {
                        feedback.push('One number');
                    }
                }

                // Check for special characters
                if (rules.requireSpecialChars) {
                    if (/[!@#$%^&*()_+\-=[\]{};':"\\|,.<>/?]/.test(password)) {
                        score += 20;
                    } else {
                        feedback.push('One special character');
                    }
                }

                const isValid = feedback.length === 0;
                return { score, feedback, isValid };
            },
            [strengthRules],
        );

        React.useEffect(() => {
            if (showStrengthIndicator && typeof value === 'string') {
                setPasswordStrength(calculatePasswordStrength(value));
            }
        }, [value, showStrengthIndicator, calculatePasswordStrength]);

        const getStrengthColor = (score: number) => {
            if (score < 40) return 'bg-red-500';
            if (score < 60) return 'bg-orange-500';
            if (score < 80) return 'bg-yellow-500';
            return 'bg-green-500';
        };

        const getStrengthText = (score: number) => {
            if (score < 40) return 'Weak';
            if (score < 60) return 'Fair';
            if (score < 80) return 'Good';
            return 'Strong';
        };

        return (
            <div className="space-y-2">
                {label && (
                    <label htmlFor={props.id} className="text-sm leading-none font-medium peer-disabled:cursor-not-allowed peer-disabled:opacity-70">
                        {label}
                    </label>
                )}

                <div className="relative">
                    <Input
                        type={showPassword ? 'text' : 'password'}
                        autoComplete="one-time-code"
                        className={cn('pr-10', className)}
                        ref={ref}
                        value={value}
                        onChange={onChange}
                        disabled={disabled}
                        placeholder={placeholder}
                        aria-describedby={showStrengthIndicator ? 'password-strength' : error ? 'password-error' : undefined}
                        aria-invalid={error ? 'true' : 'false'}
                        {...props}
                    />
                    <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        className={cn(
                            'absolute top-0 right-0 h-full rounded-l-none px-3 py-2 hover:bg-transparent focus:ring-2 focus:ring-ring focus:ring-offset-2',
                            disabled && 'cursor-not-allowed opacity-50',
                        )}
                        onClick={togglePasswordVisibility}
                        onKeyDown={handleKeyDown}
                        disabled={disabled}
                        aria-pressed={showPassword}
                        aria-label={showPassword ? 'Hide password' : 'Show password'}
                        tabIndex={0}
                    >
                        {showPassword ? <EyeOff className="h-4 w-4 text-muted-foreground" /> : <Eye className="h-4 w-4 text-muted-foreground" />}
                        <span className="sr-only">{showPassword ? 'Hide password' : 'Show password'}</span>
                    </Button>
                </div>

                {error && (
                    <p className="text-sm text-red-500" id="password-error">
                        {error}
                    </p>
                )}

                {showStrengthIndicator && value && (
                    <div id="password-strength" className="space-y-2">
                        {/* Strength bar */}
                        <div className="flex items-center space-x-2">
                            <div className="h-2 flex-1 overflow-hidden rounded-full bg-muted">
                                <div
                                    className={cn('h-full transition-all duration-300 ease-in-out', getStrengthColor(passwordStrength.score))}
                                    style={{ width: `${passwordStrength.score}%` }}
                                />
                            </div>
                            <span className="min-w-[50px] text-sm font-medium text-muted-foreground">{getStrengthText(passwordStrength.score)}</span>
                        </div>

                        {/* Requirements list */}
                        {passwordStrength.feedback.length > 0 && (
                            <div className="space-y-1">
                                <p className="flex items-center gap-1 text-sm text-muted-foreground">
                                    <AlertCircle className="h-3 w-3" />
                                    Password must contain:
                                </p>
                                <ul className="space-y-1">
                                    {passwordStrength.feedback.map((requirement, index) => (
                                        <li key={index} className="flex items-center gap-2 text-sm text-muted-foreground">
                                            <X className="h-3 w-3 text-red-500" />
                                            {requirement}
                                        </li>
                                    ))}
                                </ul>
                            </div>
                        )}

                        {/* Success message */}
                        {passwordStrength.isValid && (
                            <div className="flex items-center gap-2 text-sm text-green-600">
                                <Check className="h-3 w-3" />
                                Password meets all requirements
                            </div>
                        )}
                    </div>
                )}
            </div>
        );
    },
);

PasswordInput.displayName = 'PasswordInput';

export { PasswordInput };
