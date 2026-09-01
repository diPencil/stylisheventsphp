type AuthBrandHeadlineProps = {
  isRtl: boolean
  color: string
  size?: "hero" | "compact"
  stackRtl?: boolean
}

export function AuthBrandHeadline({ isRtl, color, size = "hero", stackRtl = false }: AuthBrandHeadlineProps) {
  const sizeClass = isRtl
    ? size === "compact"
      ? "mt-2 max-w-[560px] text-[48px] sm:text-[62px] lg:text-[72px]"
      : "mt-4 max-w-[760px] text-[82px] sm:text-[108px] lg:text-[132px]"
    : size === "compact"
      ? "mt-1 max-w-[380px] text-[42px] sm:text-[56px] lg:text-[64px]"
      : "mt-3 max-w-[620px] text-[74px] sm:text-[104px] lg:text-[132px]"

  return (
    <h1
      className={`${sizeClass} font-bold tracking-normal ${isRtl ? `${stackRtl ? "text-right" : "whitespace-nowrap text-center"} leading-[1.05]` : "leading-[0.82]"}`}
      style={{
        color,
        fontFamily: isRtl ? '"Vibes", "Cairo", Tahoma, sans-serif' : '"Caveat", "Segoe Script", cursive',
        letterSpacing: "0",
      }}
    >
      {isRtl && stackRtl ? (
        <>
          <span className="block">ستايلش</span>
          <span className="block">هوليدايز</span>
        </>
      ) : isRtl ? (
        "ستايلش هوليدايز"
      ) : (
        "Stylish Holidays"
      )}
    </h1>
  )
}
