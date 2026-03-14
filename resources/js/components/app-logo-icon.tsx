import type { ImgHTMLAttributes } from 'react';

export default function AppLogoIcon({ alt, ...props }: ImgHTMLAttributes<HTMLImageElement>) {
    return <img src="/favicon.svg" alt={alt ?? 'Kawakib'} {...props} />;
}
