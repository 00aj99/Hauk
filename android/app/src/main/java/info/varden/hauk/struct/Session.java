package info.varden.hauk.struct;

import androidx.annotation.Nullable;

import java.io.Serializable;
import java.security.NoSuchAlgorithmException;
import java.security.SecureRandom;
import java.security.spec.InvalidKeySpecException;
import java.security.spec.KeySpec;
import java.text.SimpleDateFormat;
import java.util.Date;
import java.util.Locale;

import javax.crypto.SecretKeyFactory;
import javax.crypto.spec.PBEKeySpec;
import javax.crypto.spec.SecretKeySpec;

import info.varden.hauk.Constants;
import info.varden.hauk.utils.TimeUtils;

/**
 * A data structure that contains all data required to maintain a session against a Hauk server.
 *
 * @author Marius Lindvall
 */
public final class Session implements Serializable {
    private static final long serialVersionUID = 8424014563201300999L;

    /**
     * The Hauk backend server base URL.
     */
    private final String serverURL;

    /**
     * The version the backend is running.
     */
    private final Version backendVersion;

    /**
     * A unique session ID provided by the backend to identify the session in all further
     * correspondence after session initiation. In practice, this acts as a temporary password that
     * is generated for each session and is only valid for the duration of the session.
     */
    private final String sessionID;

    /**
     * A timestamp of when the share expires, in milliseconds since the Unix epoch.
     */
    private final long expiry;

    /**
     * The interval between each location update, in seconds.
     */
    private final int interval;

    /**
     * End-to-end password to encrypt outgoing data with.
     */
    @Nullable
    private final String e2ePass;

    /**
     * Salt used in PBKDF2 for key derivation.
     */
    @Nullable
    private final byte[] salt;

    /**
     * Secret key spec cache to improve performance regarding key derivation.
     */
    @Nullable
    private transient SecretKeySpec keySpec = null;

    public Session(String serverURL, Version backendVersion, String sessionID, long expiry, int interval, @Nullable String e2ePass) {
        this.serverURL = serverURL;
        this.backendVersion = backendVersion;
        this.sessionID = sessionID;
        this.expiry = expiry;
        this.interval = interval;
        this.e2ePass = e2ePass;

        SecureRandom rand = new SecureRandom();
        this.salt = new byte[Constants.E2E_AES_KEY_SIZE / 8];
        rand.nextBytes(this.salt);
    }

    @Override
    public String toString() {
        return "Session{serverURL=" + this.serverURL
                + ",backendVersion=" + this.backendVersion
                + ",sessionID=" + this.sessionID
                + ",expiry=" + this.expiry
                + ",interval=" + this.interval
                + ",e2ePass=" + this.e2ePass
                + "}";
    }

    public String getServerURL() {
        return this.serverURL;
    }

    public Version getBackendVersion() {
        return this.backendVersion;
    }

    public String getID() {
        return this.sessionID;
    }

    @SuppressWarnings("WeakerAccess")
    public long getExpiryTime() {
        return this.expiry;
    }

    /**
     * Returns the expiration time of this session as a {@link Date} object.
     */
    @SuppressWarnings("WeakerAccess")
    public Date getExpiryDate() {
        return new Date(getExpiryTime());
    }

    /**
     * Returns the expiration time of this session as a human-readable string.
     */
    public String getExpiryString() {
        SimpleDateFormat formatter = new SimpleDateFormat(Constants.DATE_FORMAT, Locale.getDefault());
        return formatter.format(getExpiryDate());
    }

    /**
     * Whether or not this share is still active and has not expired.
     */
    public boolean isActive() {
        return System.currentTimeMillis() < getExpiryTime();
    }

    /**
     * Returns the number of seconds remaining of the location share.
     */
    public long getRemainingSeconds() {
        return getRemainingMillis() / TimeUtils.MILLIS_PER_SECOND;
    }

    /**
     * Returns the number of milliseconds remaining of the location share.
     */
    public long getRemainingMillis() {
        return getExpiryTime() - System.currentTimeMillis();
    }

    /**
     * Returns the interval between each location update, in seconds.
     */
    @SuppressWarnings("WeakerAccess")
    public int getIntervalSeconds() {
        return this.interval;
    }

    /**
     * Returns the interval between each location update, in milliseconds.
     */
    public long getIntervalMillis() {
        return getIntervalSeconds() * TimeUtils.MILLIS_PER_SECOND;
    }

    @Nullable
    public String getE2EPassword() {
        return this.e2ePass;
    }

    @Nullable
    public SecretKeySpec getKeySpec() throws NoSuchAlgorithmException, InvalidKeySpecException {
        if (this.e2ePass == null) {
            // If end-to-end encryption is not used:
            return null;
        } else if (this.keySpec == null) {
            // E2E encryption is used, but the keyspec hasn't been cached yet. Generate and cache
            // it, then return the spec.
            KeySpec ks = new PBEKeySpec(this.e2ePass.toCharArray(), this.salt, Constants.E2E_PBKDF2_ITERATIONS, Constants.E2E_AES_KEY_SIZE);
            SecretKeyFactory kf = SecretKeyFactory.getInstance(Constants.E2E_KD_FUNCTION);
            byte[] key = kf.generateSecret(ks).getEncoded();
            this.keySpec = new SecretKeySpec(key, Constants.E2E_KEY_SPEC);
        }

        return this.keySpec;
    }
}
