type AuthBrandHeadlineProps = {
  isRtl: boolean
  color: string
  size?: "hero" | "compact"
}

export function AuthBrandHeadline({ isRtl, color, size = "hero" }: AuthBrandHeadlineProps) {
  const sizeClass = isRtl
    ? size === "compact"
      ? "mt-2 max-w-[360px] text-[54px] sm:text-[72px] lg:text-[84px]"
      : "mt-4 max-w-[540px] text-[98px] sm:text-[132px] lg:text-[164px]"
    : size === "compact"
      ? "mt-1 max-w-[380px] text-[42px] sm:text-[56px] lg:text-[64px]"
      : "mt-3 max-w-[620px] text-[74px] sm:text-[104px] lg:text-[132px]"

  return (
    <h1
      className={`${sizeClass} font-bold tracking-normal ${isRtl ? "text-right leading-[0.72]" : "leading-[0.82]"}`}
      style={{
        color,
        fontFamily: isRtl ? '"Vibes", "Cairo", Tahoma, sans-serif' : '"Caveat", "Segoe Script", cursive',
        letterSpacing: "0",
      }}
    >
      {isRtl ? (
        <span className="block">
          <span className="block">ستايلش</span>
          <span className="block">هوليدايز</span>
        </span>
      ) : (
        "Stylish Holidays"
      )}
    </h1>
  )
}
